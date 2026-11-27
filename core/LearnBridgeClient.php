<?php
declare(strict_types=1);

/**
 * ==============================================================================
 * ILMUNA WA BOT — ILMUNA LEARN API BRIDGE CLIENT (HMAC-SHA256)
 * ==============================================================================
 * Klien HTTP server-to-server bertanda tangan HMAC-SHA256 + Anti-Replay
 * menuju endpoint Ilmuna Learn (`POST /api/wa-bridge.php`) sesuai kontrak
 * API_INTEGRATION.md.
 *
 * Seluruh panggilan otomatis dicatat ke `wa_learn_sync_logs` (`wa_to_learn`).
 * ==============================================================================
 */

class LearnBridgeClient
{
    private PDO $pdo;
    private string $baseUrl;
    private string $publicKey;
    private string $hmacSecret;
    private int $timeoutSec;

    /** @var callable|null */
    private $httpClient;

    public function __construct(?PDO $pdo = null, array $config = [], ?callable $httpClient = null)
    {
        $this->pdo        = $pdo ?? getDBConnection();
        $this->baseUrl    = rtrim((string)($config['base_url'] ?? wa_get_setting('learn_api_base_url', LEARN_API_BASE_URL, $this->pdo)), '/');
        $this->publicKey  = (string)($config['public_key'] ?? WA_BRIDGE_PUBLIC_KEY);
        $this->hmacSecret = (string)($config['hmac_secret'] ?? LEARN_HMAC_SECRET);
        $this->timeoutSec = (int)($config['timeout'] ?? 8);
        $this->httpClient = $httpClient;
    }

    /**
     * 1. Lookup role, profil & daftar anak (untuk orang_tua) berdasarkan nomor E.164
     */
    public function lookupContact(string $phoneE164): array
    {
        return $this->call('lookup_contact', ['phone_e164' => wa_normalize_phone($phoneE164)]);
    }

    /**
     * 2. Ringkasan Nilai, Tugas, Proyek, Absensi, Perizinan, & Catatan BK siswa
     */
    public function getStudentSummary(int $studentId, string $section = 'all'): array
    {
        return $this->call('student_summary', [
            'student_id' => $studentId,
            'section'    => $section,
        ]);
    }

    /**
     * 3. Daftar Tugas, Proyek, Agenda/Kaldik, & Asesmen CBT mendatang (H-1 / minggu ini)
     */
    public function getUpcomingAcademic(?int $kelasId = null, string $role = 'siswa', int $daysAhead = 7): array
    {
        return $this->call('upcoming_academic', [
            'kelas_id'   => $kelasId,
            'role'       => $role,
            'days_ahead' => $daysAhead,
        ]);
    }

    /**
     * 4. Approval perizinan siswa langsung dari tombol WA Guru / Wali Kelas
     */
    public function approvePerizinan(int $izinId, string $decision, string $guruPhone, string $catatan = ''): array
    {
        return $this->call('approve_perizinan', [
            'izin_id'    => $izinId,
            'decision'   => $decision, // 'disetujui' | 'ditolak'
            'guru_phone' => wa_normalize_phone($guruPhone),
            'catatan'    => $catatan,
        ]);
    }

    /**
     * 5. Catat pelanggaran grup WA oleh siswa ke tabel `catatan_pelanggaran_siswa` BK Learn
     */
    public function recordGroupViolation(
        int $siswaId,
        string $groupName,
        string $violationType,
        string $description,
        int $poin = 10
    ): array {
        return $this->call('record_group_violation', [
            'siswa_id'       => $siswaId,
            'group_name'     => $groupName,
            'violation_type' => $violationType,
            'description'    => $description,
            'poin'           => $poin,
        ]);
    }

    /**
     * 6. Ambil daftar penerima broadcast berdasarkan filter role/kelas
     */
    public function getAudienceList(string $scope, array $filter = []): array
    {
        return $this->call('audience_list', [
            'scope'  => $scope,
            'filter' => $filter,
        ]);
    }

    /**
     * 7. Ambil tautan hash aktif terbaru dari `public_form_links` (form-siswa & form-guru)
     */
    public function getPublicForms(): array
    {
        return $this->call('public_forms', []);
    }

    /**
     * 8. Ringkasan cepat untuk Guru (rekap absensi hari ini & daftar perizinan pending)
     */
    public function getTeacherOverview(string $guruPhone, string $section = 'absensi_hari_ini'): array
    {
        return $this->call('teacher_overview', [
            'guru_phone' => wa_normalize_phone($guruPhone),
            'section'    => $section,
        ]);
    }

    /**
     * 9. Ambil daftar nomor WA orang tua/wali dari siswa tertentu (untuk notifikasi Group Guard)
     */
    public function getStudentParentPhones(int $siswaId): array
    {
        return $this->call('student_parents', [
            'siswa_id' => $siswaId,
        ]);
    }

    /**
     * Kirim request POST HMAC-SHA256 ke Ilmuna Learn `/api/wa-bridge.php`
     */
    public function call(string $action, array $params = []): array
    {
        $url = $this->baseUrl . '/api/wa-bridge.php';
        $payload = array_merge(['action' => $action], $params);
        $rawBody = (string)json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $hmacHeaders = wa_sign_hmac($rawBody, $this->hmacSecret);
        $hmacHeaders['X-Ilmuna-Key'] = $this->publicKey;

        $httpHeaders = [
            'Content-Type: application/json; charset=utf-8',
            'Accept: application/json',
            'X-Ilmuna-Key: ' . $hmacHeaders['X-Ilmuna-Key'],
            'X-Ilmuna-Timestamp: ' . $hmacHeaders['X-Ilmuna-Timestamp'],
            'X-Ilmuna-Signature: ' . $hmacHeaders['X-Ilmuna-Signature'],
        ];

        $resp = $this->executeHttp($url, $httpHeaders, $rawBody);

        $decoded = is_array($resp['data']) ? $resp['data'] : [];
        $success = $resp['ok'] && (bool)($decoded['success'] ?? false);
        $message = (string)($decoded['message'] ?? $resp['error'] ?? ($success ? 'OK' : 'Gagal menghubungi Ilmuna Learn'));
        $data    = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];

        wa_log_sync(
            $this->pdo,
            'wa_to_learn',
            $action,
            $resp['http_status'],
            mb_substr($rawBody, 0, 500, 'UTF-8'),
            mb_substr($success ? $message : ('ERR: ' . $message), 0, 500, 'UTF-8')
        );

        return [
            'success'     => $success,
            'http_status' => $resp['http_status'],
            'message'     => $message,
            'data'        => $data,
        ];
    }

    private function executeHttp(string $url, array $headers, string $rawBody): array
    {
        if ($this->httpClient !== null) {
            return ($this->httpClient)('POST', $url, $headers, $rawBody);
        }

        if (!function_exists('curl_init')) {
            return ['ok' => false, 'http_status' => 0, 'data' => null, 'error' => 'Ekstensi PHP cURL tidak aktif.'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $rawBody,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT        => $this->timeoutSec,
        ]);

        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            return ['ok' => false, 'http_status' => 0, 'data' => null, 'error' => 'cURL error: ' . $err];
        }

        $decoded = json_decode((string)$body, true);
        return [
            'ok'          => $code >= 200 && $code < 300,
            'http_status' => $code,
            'data'        => is_array($decoded) ? $decoded : null,
            'error'       => $code >= 200 && $code < 300 ? null : "HTTP {$code}",
        ];
    }
}
