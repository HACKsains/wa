'use strict';

/**
 * ==============================================================================
 * ILMUNA WA BOT — LIGHTWEIGHT SELF-HOSTED MULTI-DEVICE BRIDGE (BAILEYS)
 * ==============================================================================
 * Dijalankan otomatis oleh PHP via core/BridgeManager.php (atau manual: npm start).
 * Tanpa Express / framework berat — murni Node.js `http` + Baileys + QRCode.
 *
 * Fitur:
 * - QR Code (Data URL PNG) & 8-digit Pairing Code via HTTP API
 * - Auto-forward webhook bertanda tangan HMAC-SHA256 ke PHP `webhook.php`:
 *   • call.offer (suara & video masuk -> untuk Anti-Call PHP)
 *   • group.message & message / button.reply / list.reply / poll.vote
 *   • session.status (connected / qr_pending / disconnected)
 * - Endpoint kontrol penuh untuk BridgeMdDriver.php:
 *   • GET  /api/sessions/:session/status
 *   • GET  /api/sessions/:session/qr
 *   • POST /api/sessions/:session/pairing-code
 *   • POST /api/sendText, /api/sendButtons, /api/sendPoll, /api/sendList, /api/sendMedia
 *   • POST /api/groups/deleteMessage, /api/groups/kick
 *   • POST /api/calls/reject, /api/contacts/block
 * ==============================================================================
 */

const http = require('http');
const crypto = require('crypto');
const path = require('path');
const fs = require('fs');
const pino = require('pino');
const QRCode = require('qrcode');
const {
  default: makeWASocket,
  useMultiFileAuthState,
  DisconnectReason,
  fetchLatestBaileysVersion,
} = require('@whiskeysockets/baileys');

const PORT = parseInt(process.env.BRIDGE_PORT || '38421', 10);
const SESSION = process.env.BRIDGE_SESSION || 'ilmuna_main';
const API_KEY = process.env.BRIDGE_API_KEY || '';
const WEBHOOK_URL = process.env.BRIDGE_WEBHOOK_URL || 'http://localhost/wa%20bot/webhook.php';
const WEBHOOK_SECRET = process.env.BRIDGE_WEBHOOK_SECRET || 'ilmuna_md_webhook_secret_2026';
const AUTH_DIR = path.join(__dirname, 'auth_info', SESSION);

let sock = null;
let connectionStatus = 'disconnected'; // disconnected | qr_pending | connected | idle
let latestQrRaw = null;
let latestQrDataUrl = null;
let connectedMe = null;
let reconnecting = false;
let consecutiveQrTimeouts = 0;
let lastWebhookStatus = null;

// Simpan metadata key pesan grup sementara (maks 500 pesan) agar revoke/delete 100% akurat
const recentGroupMessageKeys = new Map();
function rememberMessageKey(msgId, key) {
  if (!msgId || !key) return;
  recentGroupMessageKeys.set(msgId, key);
  if (recentGroupMessageKeys.size > 500) {
    const oldest = recentGroupMessageKeys.keys().next().value;
    recentGroupMessageKeys.delete(oldest);
  }
}

async function forwardWebhook(eventName, payloadObj) {
  if (!WEBHOOK_URL) return;
  const body = JSON.stringify({
    id: `${eventName}_${Date.now()}_${crypto.randomBytes(3).toString('hex')}`,
    event: eventName,
    session: SESSION,
    payload: payloadObj,
  });

  const sig = 'sha256=' + crypto.createHmac('sha256', WEBHOOK_SECRET).update(body).digest('hex');

  try {
    const res = await fetch(WEBHOOK_URL, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json; charset=utf-8',
        'X-Webhook-Source': 'md_bridge',
        'X-Webhook-Signature': sig,
      },
      body,
      signal: AbortSignal.timeout(10000),
    });
    if (!res.ok) {
      console.error(`[Webhook] HTTP ${res.status} untuk event ${eventName}`);
    }
  } catch (err) {
    console.error(`[Webhook] Gagal kirim ${eventName} ke ${WEBHOOK_URL}:`, err.message);
  }
}

async function initWhatsApp() {
  fs.mkdirSync(AUTH_DIR, { recursive: true });
  const { state, saveCreds } = await useMultiFileAuthState(AUTH_DIR);
  const { version } = await fetchLatestBaileysVersion();

  sock = makeWASocket({
    version,
    auth: state,
    logger: pino({ level: 'warn' }),
    browser: ['Ilmuna WA Bot', 'Chrome', '1.0.0'],
    markOnlineOnConnect: false,
    syncFullHistory: false,
  });

  sock.ev.on('creds.update', saveCreds);

  sock.ev.on('connection.update', async (update) => {
    const { connection, lastDisconnect, qr } = update;

    if (qr) {
      latestQrRaw = qr;
      connectionStatus = 'qr_pending';
      try {
        latestQrDataUrl = await QRCode.toDataURL(qr, { width: 300, margin: 2 });
      } catch {
        latestQrDataUrl = null;
      }
      // Jangan spam HTTPS webhook setiap rotasi QR 20 detik (hemat CPU & LVE shared cPanel!)
      if (lastWebhookStatus !== 'qr_pending') {
        lastWebhookStatus = 'qr_pending';
        console.log(`[WA:${SESSION}] QR tersedia — pindai dari Dashboard Admin PHP.`);
      }
    }

    if (connection === 'open') {
      connectionStatus = 'connected';
      consecutiveQrTimeouts = 0;
      latestQrRaw = null;
      latestQrDataUrl = null;
      connectedMe = sock.user || null;
      console.log(`[WA:${SESSION}] Terhubung sebagai ${connectedMe?.id || 'unknown'}`);
      if (lastWebhookStatus !== 'connected') {
        lastWebhookStatus = 'connected';
        await forwardWebhook('session.status', {
          status: 'connected',
          session: SESSION,
          me: connectedMe,
        });
      }
    }

    if (connection === 'close') {
      const wasConnected = connectionStatus === 'connected';
      connectionStatus = 'disconnected';
      latestQrRaw = null;
      latestQrDataUrl = null;
      const statusCode = lastDisconnect?.error?.output?.statusCode;
      const loggedOut = statusCode === DisconnectReason.loggedOut;
      console.warn(`[WA:${SESSION}] Koneksi tertutup (code=${statusCode}, loggedOut=${loggedOut})`);

      if (wasConnected && lastWebhookStatus !== 'disconnected') {
        lastWebhookStatus = 'disconnected';
        await forwardWebhook('session.status', { status: 'disconnected', session: SESSION });
      }

      // Jika belum pernah dipindai & timeout QR (408) berturut-turut 3x, masuk mode hemat CPU (idle)
      // dan otomatis bangun kembali saat Admin klik "Ambil QR" / "Pairing 8-Digit"!
      if (!wasConnected && (statusCode === 408 || statusCode === 428)) {
        consecutiveQrTimeouts += 1;
        if (consecutiveQrTimeouts >= 3) {
          connectionStatus = 'idle';
          console.log(`[WA:${SESSION}] QR belum dipindai (3x timeout). Masuk mode hemat CPU hingga Admin klik Ambil QR.`);
          return;
        }
      }

      if (!loggedOut && !reconnecting) {
        reconnecting = true;
        setTimeout(() => {
          reconnecting = false;
          initWhatsApp().catch((e) => console.error('[WA] Reconnect error:', e.message));
        }, 4000);
      }
    }
  });

  // 1. Tangkap panggilan masuk (Voice & Video) -> kirim ke Anti-Call PHP
  sock.ev.on('call', async (callEvents) => {
    for (const c of callEvents || []) {
      if (c.status === 'offer') {
        await forwardWebhook('call.offer', {
          call_id: c.id,
          from: c.from,
          isVideo: Boolean(c.isVideo),
          call_type: c.isVideo ? 'video' : 'voice',
          timestamp: Math.floor(Date.now() / 1000),
        });
      }
    }
  });

  // 2. Tangkap pesan masuk (Private, Grup, Button Reply, List Reply)
  sock.ev.on('messages.upsert', async ({ messages, type }) => {
    if (type !== 'notify') return;

    for (const m of messages || []) {
      if (!m.message || m.key?.fromMe) continue;
      if (m.key?.remoteJid === 'status@broadcast') continue;

      const remoteJid = m.key.remoteJid || '';
      const isGroup = remoteJid.endsWith('@g.us');
      const msgId = m.key.id || '';
      if (isGroup && msgId) {
        rememberMessageKey(msgId, m.key);
      }

      const content = m.message.ephemeralMessage?.message || m.message.viewOnceMessage?.message || m.message;
      let msgType = 'text';
      let text = '';
      let buttonId = null;
      let buttonTitle = null;

      if (content.conversation) {
        text = content.conversation;
      } else if (content.extendedTextMessage?.text) {
        text = content.extendedTextMessage.text;
      } else if (content.buttonsResponseMessage) {
        msgType = 'button_reply';
        buttonId = content.buttonsResponseMessage.selectedButtonId || '';
        buttonTitle = content.buttonsResponseMessage.selectedDisplayText || '';
        text = buttonTitle;
      } else if (content.listResponseMessage) {
        msgType = 'list_reply';
        buttonId = content.listResponseMessage.singleSelectReply?.selectedRowId || '';
        buttonTitle = content.listResponseMessage.title || '';
        text = buttonTitle;
      } else if (content.templateButtonReplyMessage) {
        msgType = 'button_reply';
        buttonId = content.templateButtonReplyMessage.selectedId || '';
        buttonTitle = content.templateButtonReplyMessage.selectedDisplayText || '';
        text = buttonTitle;
      } else if (content.imageMessage) {
        msgType = 'image';
        text = content.imageMessage.caption || '';
      } else if (content.documentMessage) {
        msgType = 'document';
        text = content.documentMessage.caption || content.documentMessage.fileName || '';
      } else if (content.videoMessage) {
        msgType = 'video';
        text = content.videoMessage.caption || '';
      } else if (content.audioMessage) {
        msgType = 'audio';
      }

      const eventName = isGroup
        ? 'group.message'
        : msgType === 'button_reply'
          ? 'button.reply'
          : msgType === 'list_reply'
            ? 'list.reply'
            : 'message';

      await forwardWebhook(eventName, {
        id: msgId,
        chatId: remoteJid,
        from: isGroup ? (m.key.participant || '') : remoteJid,
        participant: m.key.participant || '',
        pushName: m.pushName || '',
        fromMe: false,
        type: msgType,
        body: text,
        button_id: buttonId,
        button_title: buttonTitle,
        timestamp: Number(m.messageTimestamp || Math.floor(Date.now() / 1000)),
      });
    }
  });
}

function normalizeJid(input) {
  const s = String(input || '').trim();
  if (!s) return '';
  if (s.endsWith('@g.us') || s.endsWith('@s.whatsapp.net')) return s;
  if (s.endsWith('@c.us')) return s.replace(/@c\.us$/, '@s.whatsapp.net');
  const digits = s.replace(/\D+/g, '');
  if (!digits) return '';
  const e164 = digits.startsWith('0') ? '62' + digits.slice(1) : digits.startsWith('8') ? '62' + digits : digits;
  return `${e164}@s.whatsapp.net`;
}

function sendJson(res, status, data) {
  res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8' });
  res.end(JSON.stringify(data));
}

function readJsonBody(req) {
  return new Promise((resolve, reject) => {
    let raw = '';
    req.on('data', (chunk) => {
      raw += chunk;
      if (raw.length > 5 * 1024 * 1024) reject(new Error('Payload terlalu besar'));
    });
    req.on('end', () => {
      if (!raw.trim()) return resolve({});
      try {
        resolve(JSON.parse(raw));
      } catch {
        reject(new Error('JSON tidak valid'));
      }
    });
    req.on('error', reject);
  });
}

function isAuthorized(req) {
  if (!API_KEY) return true;
  const headerKey = req.headers['x-api-key'] || '';
  const auth = req.headers['authorization'] || '';
  const bearer = auth.toLowerCase().startsWith('bearer ') ? auth.slice(7).trim() : '';
  return headerKey === API_KEY || bearer === API_KEY;
}

const server = http.createServer(async (req, res) => {
  const urlObj = new URL(req.url || '/', `http://127.0.0.1:${PORT}`);
  const pathname = urlObj.pathname;
  const method = (req.method || 'GET').toUpperCase();

  if (!isAuthorized(req)) {
    return sendJson(res, 401, { error: 'API key tidak valid.' });
  }

  try {
    // 1. Status Session
    if (method === 'GET' && /^\/api\/sessions\/[^/]+\/status$/.test(pathname)) {
      return sendJson(res, 200, {
        app: 'ilmuna-wa-bridge',
        session: SESSION,
        status: connectionStatus,
        me: connectedMe,
        port: PORT,
        uptime_sec: Math.floor(process.uptime()),
      });
    }

    // 2. Ambil QR Code (PNG Data URL) — bangunkan otomatis jika sedang mode hemat CPU (idle)
    if (method === 'GET' && /^\/api\/sessions\/[^/]+\/qr$/.test(pathname)) {
      if ((connectionStatus === 'idle' || connectionStatus === 'disconnected') && !reconnecting) {
        consecutiveQrTimeouts = 0;
        reconnecting = true;
        initWhatsApp()
          .catch((e) => console.error('[WA Wake Error]:', e.message))
          .finally(() => { reconnecting = false; });
        // Tunggu sebentar agar QR pertama sempat terbit
        for (let i = 0; i < 6 && !latestQrDataUrl && connectionStatus !== 'connected'; i++) {
          await new Promise((r) => setTimeout(r, 500));
        }
      }
      return sendJson(res, 200, {
        app: 'ilmuna-wa-bridge',
        session: SESSION,
        status: connectionStatus,
        qr_data_url: latestQrDataUrl,
        qr_raw: latestQrRaw,
      });
    }

    // 3. Minta Pairing Code 8 Digit (Tanpa Scan Kamera)
    if (method === 'POST' && /^\/api\/sessions\/[^/]+\/pairing-code$/.test(pathname)) {
      const body = await readJsonBody(req);
      const phone = String(body.phone || '').replace(/\D+/g, '');
      if (!phone) {
        return sendJson(res, 400, { error: 'Nomor HP kosong.' });
      }
      if (connectionStatus === 'connected') {
        return sendJson(res, 409, { error: 'WhatsApp sudah terhubung.' });
      }
      if (!sock || connectionStatus === 'idle' || connectionStatus === 'disconnected') {
        consecutiveQrTimeouts = 0;
        await initWhatsApp();
        await new Promise((r) => setTimeout(r, 2000));
      }
      const rawCode = await sock.requestPairingCode(phone);
      const formatted = String(rawCode || '').replace(/(.{4})(?=.)/g, '$1-');
      return sendJson(res, 200, { success: true, pairing_code: formatted || rawCode, phone });
    }

    // 3B. Logout & Hapus Sesi Auth agar bisa scan nomor baru
    if (method === 'POST' && /^\/api\/sessions\/[^/]+\/logout$/.test(pathname)) {
      try {
        if (sock) await sock.logout();
      } catch {}
      connectionStatus = 'disconnected';
      latestQrRaw = null;
      latestQrDataUrl = null;
      connectedMe = null;
      fs.rmSync(AUTH_DIR, { recursive: true, force: true });
      setTimeout(() => initWhatsApp().catch((e) => console.error('[WA Reinit Error]:', e.message)), 1500);
      return sendJson(res, 200, { success: true, status: 'logged_out' });
    }

    // Pastikan WhatsApp terhubung untuk aksi kirim/moderasi
    if (!sock || connectionStatus !== 'connected') {
      return sendJson(res, 503, { error: `WhatsApp belum terhubung (status: ${connectionStatus}).` });
    }

    const body = method === 'POST' ? await readJsonBody(req) : {};

    // 4. Kirim Teks
    if (method === 'POST' && pathname === '/api/sendText') {
      const jid = normalizeJid(body.chatId);
      const msgPayload = { text: String(body.text || '') };
      if (Array.isArray(body.mentions) && body.mentions.length > 0) {
        msgPayload.mentions = body.mentions.map(normalizeJid).filter(Boolean);
      }
      const sent = await sock.sendMessage(jid, msgPayload);
      return sendJson(res, 200, { id: sent?.key?.id || null, status: 'sent' });
    }

    // 5. Kirim Buttons -> diarahkan ke 501 agar BridgeMdDriver otomatis fallback ke Poll interaktif yang 100% stabil di WA MD
    if (method === 'POST' && pathname === '/api/sendButtons') {
      return sendJson(res, 501, { error: 'Gunakan fallback Poll/List interaktif untuk stabilitas WA Multi-Device.' });
    }

    // 6. Kirim Interactive Poll (100% stabil & bisa diklik tanpa ketik di semua klien WA MD!)
    if (method === 'POST' && pathname === '/api/sendPoll') {
      const jid = normalizeJid(body.chatId);
      const poll = body.poll || {};
      const sent = await sock.sendMessage(jid, {
        poll: {
          name: String(poll.name || 'Pilih Menu:'),
          values: Array.isArray(poll.options) ? poll.options.slice(0, 12).map(String) : ['Menu Utama'],
          selectableCount: poll.multipleAnswers ? 0 : 1,
        },
      });
      return sendJson(res, 200, { id: sent?.key?.id || null, status: 'sent' });
    }

    // 7. Kirim List -> fallback otomatis ke Poll via 501
    if (method === 'POST' && pathname === '/api/sendList') {
      return sendJson(res, 501, { error: 'Gunakan fallback Poll interaktif.' });
    }

    // 8. Kirim Media (Image / Document / Video / Audio)
    if (method === 'POST' && pathname === '/api/sendMedia') {
      const jid = normalizeJid(body.chatId);
      const mType = String(body.mediaType || 'image').toLowerCase();
      const mediaUrl = String(body.mediaUrl || '');
      const caption = String(body.caption || '');
      const payload =
        mType === 'document'
          ? { document: { url: mediaUrl }, fileName: body.filename || 'dokumen.pdf', caption }
          : mType === 'video'
            ? { video: { url: mediaUrl }, caption }
            : mType === 'audio'
              ? { audio: { url: mediaUrl }, mimetype: 'audio/mp4' }
              : { image: { url: mediaUrl }, caption };
      const sent = await sock.sendMessage(jid, payload);
      return sendJson(res, 200, { id: sent?.key?.id || null, status: 'sent' });
    }

    // 9. Hapus Pesan Pelanggaran di Grup (Revoke for everyone)
    if (method === 'POST' && pathname === '/api/groups/deleteMessage') {
      const groupJid = normalizeJid(body.groupJid);
      const messageId = String(body.messageId || '');
      const storedKey = recentGroupMessageKeys.get(messageId);
      const key = storedKey || {
        remoteJid: groupJid,
        fromMe: false,
        id: messageId,
        participant: body.participantJid ? normalizeJid(body.participantJid) : undefined,
      };
      await sock.sendMessage(groupJid, { delete: key });
      return sendJson(res, 200, { success: true, deleted: messageId });
    }

    // 10. Kick Anggota Grup
    if (method === 'POST' && pathname === '/api/groups/kick') {
      const groupJid = normalizeJid(body.groupJid);
      const participants = (Array.isArray(body.participants) ? body.participants : []).map(normalizeJid).filter(Boolean);
      const resp = await sock.groupParticipantsUpdate(groupJid, participants, 'remove');
      return sendJson(res, 200, { success: true, result: resp });
    }

    // 11. Tolak Panggilan Masuk (Anti-Call)
    if (method === 'POST' && pathname === '/api/calls/reject') {
      const callId = String(body.callId || '');
      const from = normalizeJid(body.from);
      await sock.rejectCall(callId, from);
      return sendJson(res, 200, { success: true, rejected: callId });
    }

    // 12. Blokir Kontak Spam Call
    if (method === 'POST' && pathname === '/api/contacts/block') {
      const chatId = normalizeJid(body.chatId);
      await sock.updateBlockStatus(chatId, 'block');
      return sendJson(res, 200, { success: true, blocked: chatId });
    }

    return sendJson(res, 404, { error: `Endpoint ${method} ${pathname} tidak ditemukan.` });
  } catch (err) {
    console.error(`[HTTP ${pathname}] Error:`, err.message);
    return sendJson(res, 500, { error: err.message });
  }
});

server.on('error', (err) => {
  console.error(`[Ilmuna WA Bridge] Server bind error on 127.0.0.1:${PORT}: ${err.code || err.message}`);
  process.exit(1);
});

server.listen(PORT, '127.0.0.1', () => {
  console.log(`[Ilmuna WA Bridge] Listening on http://127.0.0.1:${PORT} (session=${SESSION})`);
  initWhatsApp().catch((e) => console.error('[WA Init Error]:', e.message));
});

function gracefulShutdown(signal) {
  console.log(`[Ilmuna WA Bridge] Menerima sinyal ${signal}, menutup server dengan bersih...`);
  try { server.close(); } catch {}
  process.exit(0);
}
process.on('SIGTERM', () => gracefulShutdown('SIGTERM'));
process.on('SIGINT', () => gracefulShutdown('SIGINT'));
