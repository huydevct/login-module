<?php

namespace Modules\Login\Http\Controllers\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Modules\Login\Http\Controllers\Controller;
use Modules\Login\Services\Attestation\AttestationException;
use Modules\Login\Services\Attestation\AttestationVerifier;
use Modules\Login\Services\DeviceService\DeviceGet;

/**
 * Dang ky public key cua key trong Android Keystore cho device dang dang nhap (JWT).
 */
class AttestController extends Controller
{
    public static function challengeKey(int $deviceId): string
    {
        return "login:attest:{$deviceId}";
    }

    public function challenge(Request $request)
    {
        $challenge = random_bytes(32);
        Cache::put(
            self::challengeKey($this->deviceId($request)),
            base64_encode($challenge),
            (int) config('login.attestation.challenge_ttl')
        );

        return $this->response(['challenge' => base64_encode($challenge)]);
    }

    public function register(Request $request, AttestationVerifier $verifier)
    {
        $data = $request->validate([
            'chain' => ['required', 'array', 'min:2', 'max:10'],
            'chain.*' => ['required', 'string'],
        ]);
        $deviceId = $this->deviceId($request);

        // pull = doc xong xoa -> challenge chi dung 1 lan
        $challenge = Cache::pull(self::challengeKey($deviceId));
        if (empty($challenge)) {
            return $this->response(['message' => 'Challenge không tồn tại hoặc đã hết hạn'], 400);
        }

        $device = DeviceGet::getById($deviceId);
        $package = $device?->app?->package_id;
        if (empty($package)) {
            return $this->response(['message' => 'Attestation: Thiết bị không gắn với app'], 403);
        }

        // Khong dung config("...signature_digests.$package"): package co dau cham.
        $allDigests = config('login.attestation.signature_digests') ?? [];
        $digests = (array) ($allDigests[$package] ?? []);

        try {
            $result = $verifier->verify($data['chain'], base64_decode($challenge), $package, $digests);
        } catch (AttestationException $e) {
            return $this->response(['message' => 'Attestation: '.$e->getMessage()], 403);
        }

        $device->update([
            'public_key_pem' => $result['public_key_pem'],
            'security_level' => $result['security_level'],
            'attested_at' => now(),
        ]);

        return $this->response(['security_level' => $result['security_level']]);
    }

    /**
     * Device cua chinh request nay (ApiAuthenticate gan), khong doc singleton AuthApi.
     */
    private function deviceId(Request $request): int
    {
        return (int) ($request->attributes->get('login_auth')['device_id'] ?? 0);
    }
}
