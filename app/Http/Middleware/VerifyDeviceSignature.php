<?php

namespace Modules\Login\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Response;
use Modules\Login\Services\DeviceService\DeviceGet;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * Kiem tra chu ky request bang public key da attest cua device. Dat SAU auth.api.
 *
 * Header: X-Timestamp (epoch giay), X-Nonce (1-64 ky tu), X-Signature (base64 ECDSA-SHA256 DER).
 * Chuoi ky (noi bang \n, khong co \n cuoi):
 *   METHOD, /PATH, QUERY (chuoi query goc, rong neu khong co), TIMESTAMP, NONCE, DEVICE_ID, APP_ID, SHA256_HEX(body)
 */
class VerifyDeviceSignature
{
    /**
     * @param  Closure(Request): (SymfonyResponse)  $next
     */
    public function handle(Request $request, Closure $next): SymfonyResponse
    {
        // Doc tu request (ApiAuthenticate gan), khong doc singleton AuthApi.
        $auth = $request->attributes->get('login_auth');
        if (empty($auth['device_id'])) {
            return $this->deny(401, 'Not auth', 'not_authenticated');
        }

        // PHP-FPM khong cho doc php://input voi multipart -> body se khong duoc ky, form sua tuy y
        if (str_starts_with(strtolower((string) $request->header('Content-Type', '')), 'multipart/')) {
            return $this->deny(400, 'Multipart is not supported', 'unsupported_content_type');
        }

        $timestamp = (string) $request->header('X-Timestamp', '');
        $nonce = (string) $request->header('X-Nonce', '');
        $signature = (string) $request->header('X-Signature', '');
        if (! ctype_digit($timestamp) || $nonce === '' || strlen($nonce) > 64 || $signature === '') {
            return $this->deny(400, 'Missing signature header', 'missing_signature_header');
        }

        $window = (int) config('login.attestation.timestamp_window');
        if (abs(time() - (int) $timestamp) > $window) {
            return $this->deny(401, 'Request expired', 'request_expired');
        }

        $device = DeviceGet::getById((int) $auth['device_id']);
        if (empty($device?->public_key_pem)) {
            return $this->deny(403, 'Device not attested', 'device_not_attested');
        }

        $payload = implode("\n", [
            $request->method(),
            '/'.ltrim($request->path(), '/'),
            (string) $request->server('QUERY_STRING', ''),
            $timestamp,
            $nonce,
            $auth['device_id'],
            $auth['app_id'],
            hash('sha256', $request->getContent()),
        ]);
        $raw = base64_decode($signature, true);
        if ($raw === false || @openssl_verify($payload, $raw, $device->public_key_pem, OPENSSL_ALGO_SHA256) !== 1) {
            return $this->deny(401, 'Invalid signature', 'invalid_signature');
        }

        // Nonce dung 1 lan; chi danh dau SAU khi chu ky dung. Cache::add nguyen tu tren Redis.
        if (! Cache::add("login:nonce:{$auth['device_id']}:{$nonce}", 1, $window * 2)) {
            return $this->deny(401, 'Replayed request', 'replayed_request');
        }

        $request->attributes->set('login_device', $device);

        return $next($request);
    }

    private function deny(int $code, string $message, string $status): SymfonyResponse
    {
        return Response::json(['code' => $code, 'message' => $message.',[VerifyDeviceSignature]', 'status' => $status], $code);
    }
}
