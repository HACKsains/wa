'use strict';

/**
 * ==============================================================================
 * ILMUNA WA BOT — LIGHTWEIGHT SELF-HOSTED MULTI-DEVICE BRIDGE (BAILEYS)
 * ==============================================================================
 * Dijalankan otomatis oleh PHP via core/BridgeManager.php (tanpa SSH/CLI!).
 * Tanpa Express / framework berat — murni Node.js `http` + Baileys + QRCode.
 *
 * Fitur Produksi:
 * - Anti "Menunggu pesan ini...": dilengkapi `getMessage` store (1.000 pesan),
 *   `msgRetryCounterCache`, & `makeCacheableSignalKeyStore` untuk Signal E2E!
 * - Dukungan penuh WhatsApp `@lid` (`remoteJidAlt`, `senderPn`, `participantAlt`,
 *   & pemetaan LID -> Nomor HP dari kontak)
 * - Interactive Buttons / List -> menu bernomor rapi 100% instan di semua klien WA
 *   + dukungan penuh Interactive Poll (`messageSecret` + `decryptPollVote`)
 * - Auto-forward webhook bertanda tangan HMAC-SHA256 ke PHP `webhook.php`:
 *   • call.offer (suara & video masuk -> Anti-Call PHP)
 *   • group.message & message / button.reply / list.reply / poll.vote
 *   • session.status (hanya saat transisi status nyata, hemat CPU/LVE cPanel)
 * - Mode hemat CPU (`idle`) otomatis jika QR tidak dipindai 3x timeout
 * ==============================================================================
 */

const http = require('http');
const crypto = require('crypto');
const path = require('path');
const fs = require('fs');

// Saring spam debug internal `libsignal` ("Closing session: SessionEntry { privKey... }")
const _origInfo = console.info.bind(console);
console.info = (...args) => {
  const first = typeof args[0] === 'string' ? args[0] : '';
  if (
    first.startsWith('Closing session:') ||
    first.startsWith('Closing stale open session') ||
    first.startsWith('Removing old closed session') ||
    first.startsWith('Migrating session to:') ||
    first.startsWith('Opening session:')
  ) {
    return;
  }
  _origInfo(...args);
};

const pino = require('pino');
const QRCode = require('qrcode');
const {
  default: makeWASocket,
  useMultiFileAuthState,
  DisconnectReason,
  fetchLatestBaileysVersion,
  makeCacheableSignalKeyStore,
  getAggregateVotesInPollMessage,
} = require('@whiskeysockets/baileys');

const PORT = parseInt(process.env.BRIDGE_PORT || '38421', 10);
const SESSION = process.env.BRIDGE_SESSION || 'ilmuna_main';
const API_KEY = process.env.BRIDGE_API_KEY || '';
const WEBHOOK_URL = process.env.BRIDGE_WEBHOOK_URL || 'http://localhost/wa%20bot/webhook.php';
const WEBHOOK_SECRET = process.env.BRIDGE_WEBHOOK_SECRET || 'ilmuna_md_webhook_secret_2026';
const AUTH_DIR = path.join(__dirname, 'auth_info', SESSION);

let antiCallEnabled = process.env.BRIDGE_ANTI_CALL !== '0';

let sock = null;
let connectionStatus = 'disconnected'; // disconnected | qr_pending | connected | idle
let latestQrRaw = null;
let latestQrDataUrl = null;
let connectedMe = null;
let reconnecting = false;
let consecutiveQrTimeouts = 0;
let lastWebhookStatus = null;

// Cache panggilan aktif: simpan JID mentah `c.from` (wajib sama persis utk `rejectCall`, termasuk @lid)
// + set idempotensi agar reject tidak dipanggil dua kali pada offer -> ringing / callback PHP
const callRawFromMap = new Map();
const handledCallIds = new Set();
const rejectedCallIds = new Set();
function rememberBoundedSet(set, value, maxSize = 200) {
  set.add(value);
  if (set.size > maxSize) {
    set.delete(set.values().next().value);
  }
}
function rememberCallRawFrom(callId, rawFrom) {
  if (!callId || !rawFrom) return;
  callRawFromMap.set(callId, rawFrom);
  if (callRawFromMap.size > 200) {
    callRawFromMap.delete(callRawFromMap.keys().next().value);
  }
}

// Cache retry Signal ringan (kompatibel interface NodeCache tanpa paket tambahan)
function createRetryCache(maxEntries = 1000) {
  const map = new Map();
  return {
    get(key) { return map.get(key); },
    set(key, val) {
      map.set(key, val);
      if (map.size > maxEntries) {
        const first = map.keys().next().value;
        map.delete(first);
      }
      return true;
    },
    del(key) { map.delete(key); },
    take(key) { const v = map.get(key); map.delete(key); return v; },
    flushAll() { map.clear(); },
  };
}
const msgRetryCounterCache = createRetryCache(1000);

// Store pesan terkirim & masuk (maks 1.000) untuk `getMessage` Signal Retry Receipt & dekripsi Poll!
// Wajib ada di Baileys agar penerima TIDAK PERNAH melihat "Menunggu pesan ini..."!
const messageStore = new Map();
function rememberMessage(msg) {
  const id = msg?.key?.id;
  if (!id || !msg?.message) return;
  messageStore.set(id, msg);
  if (messageStore.size > 1000) {
    const oldest = messageStore.keys().next().value;
    messageStore.delete(oldest);
  }
}

// Pemetaan LID -> JID nomor HP asli + reverse lookup angka LID -> JID asli (@lid)
const lidToPnMap = new Map();
const lidDigitsToJid = new Map();
function rememberLidMapping(lid, pnJid) {
  if (!lid) return;
  const cleanLid = String(lid).split(':')[0];
  if (cleanLid.endsWith('@lid')) {
    const lidDigits = cleanLid.split('@')[0].replace(/\D+/g, '');
    if (lidDigits) {
      lidDigitsToJid.set(lidDigits, cleanLid);
      if (lidDigitsToJid.size > 2000) {
        lidDigitsToJid.delete(lidDigitsToJid.keys().next().value);
      }
    }
  }
  if (!pnJid) return;
  const cleanPn = String(pnJid).split(':')[0];
  if (cleanLid.endsWith('@lid') && cleanPn.endsWith('@s.whatsapp.net')) {
    lidToPnMap.set(cleanLid, cleanPn);
    if (lidToPnMap.size > 2000) {
      const oldest = lidToPnMap.keys().next().value;
      lidToPnMap.delete(oldest);
    }
  }
}

function resolveJidFromLid(rawJid, altJid) {
  const raw = String(rawJid || '').trim();
  const alt = String(altJid || '').trim();
  if (raw.endsWith('@lid')) {
    rememberLidMapping(raw, alt && alt.endsWith('@s.whatsapp.net') ? alt : null);
  }
  if (alt && alt.endsWith('@s.whatsapp.net')) {
    return alt.split(':')[0];
  }
  if (raw.endsWith('@lid')) {
    const baseLid = raw.split(':')[0];
    const mapped = lidToPnMap.get(baseLid) || lidToPnMap.get(raw);
    if (mapped) return mapped;
    return baseLid;
  }
  return raw;
}

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

// Helper kirim pesan + otomatis simpan ke messageStore (anti "Menunggu pesan ini...")
async function sendWaMessage(jid, content, opts = {}) {
  const sent = await sock.sendMessage(jid, content, opts);
  if (sent) rememberMessage(sent);
  console.log(`[WA:OUT] to=${jid} id=${sent?.key?.id || '-'}`);
  return sent;
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
      signal: AbortSignal.timeout(12000),
    });
    const resText = await res.text().catch(() => '');
    if (!res.ok) {
      console.error(`[Webhook] HTTP ${res.status} untuk event ${eventName}: ${resText.slice(0, 200)}`);
    } else if (eventName !== 'session.status') {
      let summary = 'ok';
      try {
        const j = JSON.parse(resText);
        const firstRes = j?.data?.results?.[0];
        if (firstRes) {
          summary = `${firstRes.route_target || ''}:${firstRes.status || 'ok'}`;
          const sendErr = firstRes?.detail?.send_result?.error || firstRes?.detail?.reply_result?.error;
          if (sendErr) summary += ` (err: ${sendErr})`;
        }
      } catch {}
      console.log(`[Webhook] OK ${eventName} -> ${summary}`);
    }
  } catch (err) {
    console.error(`[Webhook] Gagal kirim ${eventName} ke ${WEBHOOK_URL}:`, err.message);
  }
}

async function initWhatsApp() {
  fs.mkdirSync(AUTH_DIR, { recursive: true });
  const { state, saveCreds } = await useMultiFileAuthState(AUTH_DIR);
  const { version } = await fetchLatestBaileysVersion();
  const logger = pino({ level: 'warn' });

  const authConfig = typeof makeCacheableSignalKeyStore === 'function'
    ? { creds: state.creds, keys: makeCacheableSignalKeyStore(state.keys, logger) }
    : state;

  sock = makeWASocket({
    version,
    auth: authConfig,
    logger,
    browser: ['Ilmuna WA Bot', 'Chrome', '1.0.0'],
    markOnlineOnConnect: false,
    syncFullHistory: false,
    msgRetryCounterCache,
    getMessage: async (key) => {
      const stored = messageStore.get(key?.id);
      return stored?.message || undefined;
    },
  });

  sock.ev.on('creds.update', saveCreds);

  // Kumpulkan pemetaan LID -> Nomor HP dari daftar kontak, sinkronisasi, & lid-mapping
  sock.ev.on('contacts.upsert', (contacts) => {
    for (const c of contacts || []) {
      if (c?.lid && c?.id) rememberLidMapping(c.lid, c.id);
    }
  });
  sock.ev.on('contacts.update', (updates) => {
    for (const c of updates || []) {
      if (c?.lid && c?.id) rememberLidMapping(c.lid, c.id);
    }
  });
  sock.ev.on('lid-mapping.update', (mapping) => {
    if (mapping && typeof mapping === 'object') {
      if (mapping.lid && mapping.pn) rememberLidMapping(mapping.lid, mapping.pn);
      for (const [k, v] of Object.entries(mapping)) {
        if (String(k).endsWith('@lid') && String(v).endsWith('@s.whatsapp.net')) {
          rememberLidMapping(k, v);
        } else if (String(v).endsWith('@lid') && String(k).endsWith('@s.whatsapp.net')) {
          rememberLidMapping(v, k);
        }
      }
    }
  });

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
      if (connectedMe?.lid && connectedMe?.id) {
        rememberLidMapping(connectedMe.lid, connectedMe.id);
      }
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

      // Hemat CPU cPanel: jika QR timeout 3x berturut-turut tanpa dipindai, masuk mode idle
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

  // 1. Tangkap panggilan masuk (Voice & Video) -> INSTANT REJECT (<10ms) dgn JID mentah + teruskan ke Anti-Call PHP
  sock.ev.on('call', async (callEvents) => {
    for (const c of callEvents || []) {
      if (!c || !c.id) continue;
      console.log(
        `[WA:CALL] status=${c.status} id=${c.id} from=${c.from || ''} chatId=${c.chatId || ''} callerPn=${c.callerPn || ''} video=${Boolean(c.isVideo)}`
      );

      if (c.status !== 'offer' && c.status !== 'ringing') continue;
      if (handledCallIds.has(c.id)) continue;
      rememberBoundedSet(handledCallIds, c.id);

      const rawFrom = String(c.from || c.chatId || '').trim();
      rememberCallRawFrom(c.id, rawFrom);

      // Instant Reject langsung dari Baileys memakai `c.from` MENTAH (mendukung @lid & :deviceId 100%!)
      let rejectedNow = false;
      if (antiCallEnabled && rawFrom) {
        try {
          await sock.rejectCall(c.id, rawFrom);
          rejectedNow = true;
          rememberBoundedSet(rejectedCallIds, c.id);
          console.log(`[WA:CALL] Auto-reject instan berhasil: id=${c.id} from=${rawFrom}`);
        } catch (err) {
          console.error(`[WA:CALL] Gagal auto-reject (${rawFrom}):`, err.message);
          // Fallback bila `chatId` berbeda dari `from` (mis. group/LID call)
          if (c.chatId && c.chatId !== rawFrom) {
            try {
              await sock.rejectCall(c.id, c.chatId);
              rejectedNow = true;
              rememberBoundedSet(rejectedCallIds, c.id);
              console.log(`[WA:CALL] Auto-reject fallback berhasil via chatId=${c.chatId}`);
            } catch (err2) {
              console.error(`[WA:CALL] Fallback reject gagal (${c.chatId}):`, err2.message);
            }
          }
        }
      }

      const altPn = c.callerPn || (c.chatId && String(c.chatId).endsWith('@s.whatsapp.net') ? c.chatId : '');
      const callerJid = resolveJidFromLid(rawFrom, altPn);

      await forwardWebhook('call.offer', {
        call_id: c.id,
        from: callerJid,
        raw_from: rawFrom,
        already_rejected: rejectedNow,
        isVideo: Boolean(c.isVideo),
        call_type: c.isVideo ? 'video' : 'voice',
        timestamp: Math.floor(Date.now() / 1000),
      });
    }
  });

  // 2. Tangkap klik Poll (pollUpdateMessage) dan teruskan sebagai poll.vote ke PHP
  sock.ev.on('messages.update', async (updates) => {
    for (const { key, update } of updates || []) {
      if (!update?.pollUpdates || typeof getAggregateVotesInPollMessage !== 'function') continue;
      const pollCreation = messageStore.get(key?.id);
      if (!pollCreation?.message) continue;
      try {
        const votes = getAggregateVotesInPollMessage({
          message: pollCreation.message,
          pollUpdates: update.pollUpdates,
        });
        const chosen = (votes || []).find((v) => Array.isArray(v.voters) && v.voters.length > 0);
        if (!chosen) continue;
        const voterRaw = chosen.voters[chosen.voters.length - 1] || key.remoteJid || '';
        const voterJid = resolveJidFromLid(voterRaw, key.remoteJidAlt);
        if (!voterJid || voterJid === connectedMe?.id?.split(':')[0] + '@s.whatsapp.net') continue;
        console.log(`[WA:POLL] from=${voterJid} option="${chosen.name}"`);
        await forwardWebhook('poll.vote', {
          id: `poll_${key.id}_${Date.now()}`,
          chatId: voterJid,
          from: voterJid,
          fromMe: false,
          type: 'poll_vote',
          selectedOptions: [chosen.name],
          body: chosen.name,
          timestamp: Math.floor(Date.now() / 1000),
        });
      } catch (e) {
        console.error('[WA:POLL] Gagal dekripsi vote:', e.message);
      }
    }
  });

  // 3. Tangkap pesan masuk (Private, Grup, Button Reply, List Reply)
  sock.ev.on('messages.upsert', async ({ messages, type }) => {
    if (type !== 'notify') return;

    for (const m of messages || []) {
      if (!m.message || m.key?.fromMe) continue;
      const rawRemoteJid = m.key?.remoteJid || '';
      if (!rawRemoteJid || rawRemoteJid === 'status@broadcast' || rawRemoteJid.endsWith('@newsletter')) continue;

      rememberMessage(m);

      const isGroup = rawRemoteJid.endsWith('@g.us');
      const remoteJid = isGroup
        ? rawRemoteJid
        : resolveJidFromLid(rawRemoteJid, m.key.remoteJidAlt || m.key.senderPn);
      const rawParticipant = m.key.participant || '';
      const resolvedParticipant = resolveJidFromLid(rawParticipant, m.key.participantAlt || m.key.senderPn);
      const msgId = m.key.id || '';
      if (isGroup && msgId) {
        rememberMessageKey(msgId, m.key);
      }

      const content =
        m.message.ephemeralMessage?.message ||
        m.message.viewOnceMessage?.message ||
        m.message.viewOnceMessageV2?.message ||
        m.message;

      // Abaikan protokol / reaksi kosong
      if (content.protocolMessage || content.senderKeyDistributionMessage && !content.conversation && !content.extendedTextMessage) {
        continue;
      }

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
      } else {
        continue;
      }

      const eventName = isGroup
        ? 'group.message'
        : msgType === 'button_reply'
          ? 'button.reply'
          : msgType === 'list_reply'
            ? 'list.reply'
            : 'message';

      const senderTarget = isGroup ? resolvedParticipant : remoteJid;
      console.log(`[WA:IN] ${eventName} from=${senderTarget} text="${text.slice(0, 60)}"`);

      await forwardWebhook(eventName, {
        id: msgId,
        chatId: remoteJid,
        from: senderTarget,
        participant: resolvedParticipant,
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
  if (s.endsWith('@g.us')) return s;
  if (s.endsWith('@lid')) {
    const cleanLid = s.split(':')[0];
    return lidToPnMap.get(cleanLid) || cleanLid;
  }
  const userPart = s.split('@')[0].split(':')[0];
  const digits = userPart.replace(/\D+/g, '');
  if (!digits) return '';
  // Jika PHP menormalisasi @lid yang belum punya PN menjadi `<lid>@s.whatsapp.net`, kembalikan ke JID aslinya!
  if (lidDigitsToJid.has(digits)) {
    const lidJid = lidDigitsToJid.get(digits);
    return lidToPnMap.get(lidJid) || lidJid;
  }
  if (s.endsWith('@s.whatsapp.net')) return `${digits}@s.whatsapp.net`;
  if (s.endsWith('@c.us')) return `${digits}@s.whatsapp.net`;
  const e164 = digits.startsWith('0') ? '62' + digits.slice(1) : digits.startsWith('8') ? '62' + digits : digits;
  return `${e164}@s.whatsapp.net`;
}

function formatInteractiveText(header, body, items, footer) {
  const numEmoji = ['1️⃣', '2️⃣', '3️⃣', '4️⃣', '5️⃣', '6️⃣', '7️⃣', '8️⃣', '9️⃣', '🔟'];
  const lines = [];
  if (header && String(header).trim() !== '') {
    lines.push(`*${String(header).trim()}*`);
    lines.push('━━━━━━━━━━━━━━━━━━');
  }
  if (body && String(body).trim() !== '') {
    lines.push(String(body).trim());
  }
  if (Array.isArray(items) && items.length > 0) {
    lines.push('');
    let hasUrlOnly = true;
    items.forEach((btn, idx) => {
      const icon = numEmoji[idx] || `${idx + 1}.`;
      if (btn.type === 'url' && btn.url) {
        lines.push(`🔗 *${btn.title || 'Buka Tautan'}:*`);
        lines.push(String(btn.url));
      } else {
        hasUrlOnly = false;
        const desc = btn.description ? `\n    _${btn.description}_` : '';
        lines.push(`${icon} *${btn.title}*${desc}`);
      }
    });
    if (!hasUrlOnly) {
      lines.push('');
      lines.push('💡 _Balas dengan angka (1, 2, 3...) untuk memilih menu._');
    }
  }
  if (footer && String(footer).trim() !== '') {
    lines.push('');
    lines.push(`_${String(footer).trim()}_`);
  }
  return lines.join('\n');
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
        anti_call_enabled: antiCallEnabled,
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

    // 3C. Graceful shutdown via HTTP agar PHP bisa menghentikan daemon dalam <5ms
    if (method === 'POST' && /^\/api\/sessions\/[^/]+\/shutdown$/.test(pathname)) {
      sendJson(res, 200, { success: true, status: 'shutting_down' });
      setTimeout(() => gracefulShutdown('HTTP_SHUTDOWN'), 100);
      return;
    }

    // 3D. Sinkron toggle Anti-Call secara live dari Dashboard PHP tanpa perlu restart bridge
    if (method === 'POST' && pathname === '/api/config/anti-call') {
      const cfg = await readJsonBody(req);
      antiCallEnabled = Boolean(cfg.enabled);
      console.log(`[WA:CONFIG] Instant Anti-Call auto-reject = ${antiCallEnabled ? 'ON' : 'OFF'}`);
      return sendJson(res, 200, { success: true, anti_call_enabled: antiCallEnabled });
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
      const sent = await sendWaMessage(jid, msgPayload);
      return sendJson(res, 200, { id: sent?.key?.id || null, status: 'sent' });
    }

    // 5. Kirim Buttons -> format teks bernomor 100% instan di semua klien WA (ZERO "Menunggu pesan ini...")
    if (method === 'POST' && pathname === '/api/sendButtons') {
      const jid = normalizeJid(body.chatId);
      const text = formatInteractiveText(body.header, body.body, body.buttons || [], body.footer);
      const sent = await sendWaMessage(jid, { text });
      return sendJson(res, 200, { id: sent?.key?.id || null, status: 'sent', mode: 'formatted_menu' });
    }

    // 6. Kirim List -> format daftar bernomor rapi 100% instan di semua klien WA
    if (method === 'POST' && pathname === '/api/sendList') {
      const jid = normalizeJid(body.chatId);
      const flatItems = [];
      for (const sec of Array.isArray(body.sections) ? body.sections : []) {
        for (const row of Array.isArray(sec.rows) ? sec.rows : []) {
          if (row && row.title) flatItems.push(row);
        }
      }
      const text = formatInteractiveText(body.header, body.body, flatItems, body.footer);
      const sent = await sendWaMessage(jid, { text });
      return sendJson(res, 200, { id: sent?.key?.id || null, status: 'sent', mode: 'formatted_list' });
    }

    // 7. Kirim Interactive Poll (dilengkapi messageSecret + messageStore untuk dekripsi vote akurat)
    if (method === 'POST' && pathname === '/api/sendPoll') {
      const jid = normalizeJid(body.chatId);
      const poll = body.poll || {};
      const sent = await sendWaMessage(jid, {
        poll: {
          name: String(poll.name || 'Pilih Menu:'),
          values: Array.isArray(poll.options) ? poll.options.slice(0, 12).map(String) : ['Menu Utama'],
          selectableCount: poll.multipleAnswers ? 0 : 1,
          messageSecret: crypto.randomBytes(32),
        },
      });
      return sendJson(res, 200, { id: sent?.key?.id || null, status: 'sent' });
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
      const sent = await sendWaMessage(jid, payload);
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

    // 11. Tolak Panggilan Masuk (Anti-Call — gunakan JID mentah `callRawFromMap` & hindari reject ganda)
    if (method === 'POST' && pathname === '/api/calls/reject') {
      const callId = String(body.callId || '');
      if (callId && rejectedCallIds.has(callId)) {
        return sendJson(res, 200, { success: true, rejected: callId, already_rejected: true });
      }
      const from = (callId && callRawFromMap.get(callId)) || normalizeJid(body.from);
      await sock.rejectCall(callId, from);
      if (callId) rememberBoundedSet(rejectedCallIds, callId);
      console.log(`[WA:CALL] Rejected via API: id=${callId} from=${from}`);
      return sendJson(res, 200, { success: true, rejected: callId });
    }

    // 12. Blokir Kontak Spam Call
    if (method === 'POST' && pathname === '/api/contacts/block') {
      const chatId = normalizeJid(body.chatId);
      await sock.updateBlockStatus(chatId, 'block');
      return sendJson(res, 200, { success: true, blocked: chatId });
    }

    // 13. Buka Blokir Kontak (sinkron dengan tombol Buka Blokir di Dashboard Anti-Call PHP)
    if (method === 'POST' && pathname === '/api/contacts/unblock') {
      const chatId = normalizeJid(body.chatId);
      await sock.updateBlockStatus(chatId, 'unblock');
      return sendJson(res, 200, { success: true, unblocked: chatId });
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
