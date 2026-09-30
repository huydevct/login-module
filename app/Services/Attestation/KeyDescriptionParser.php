<?php

namespace Modules\Login\Services\Attestation;

use phpseclib3\File\ASN1;
use phpseclib3\File\X509;
use Throwable;

/**
 * Doc extension Key Attestation cua cert leaf.
 *
 * KeyDescription ::= SEQUENCE {
 *   attestationVersion, attestationSecurityLevel (0 Software, 1 TEE, 2 StrongBox),
 *   keyMintVersion, keyMintSecurityLevel, attestationChallenge OCTET STRING, uniqueId,
 *   softwareEnforced AuthorizationList, hardwareEnforced AuthorizationList }
 *
 * attestationApplicationId = [709] EXPLICIT OCTET STRING trong softwareEnforced, chua DER cua
 *   SEQUENCE { package_infos SET OF SEQUENCE { package_name, version }, signature_digests SET OF OCTET STRING }
 *
 * rootOfTrust = [704] EXPLICIT trong hardwareEnforced:
 *   SEQUENCE { verifiedBootKey, deviceLocked BOOLEAN, verifiedBootState ENUMERATED (0 Verified), verifiedBootHash }
 */
class KeyDescriptionParser
{
    public const OID = '1.3.6.1.4.1.11129.2.1.17';

    private const TAG_APPLICATION_ID = 709;

    private const TAG_ROOT_OF_TRUST = 704;

    /**
     * @return array{security_level: int, keymint_security_level: int, challenge: string, package: string,
     *                digests: string[], device_locked: ?bool, verified_boot_state: ?int}
     */
    public function parse(string $leafDer): array
    {
        try {
            $cert = (new X509)->loadX509($leafDer);
        } catch (Throwable) {
            $cert = false;
        }
        if (! is_array($cert)) {
            throw new AttestationException('Không đọc được cert leaf');
        }

        $extension = null;
        foreach ($cert['tbsCertificate']['extensions'] ?? [] as $item) {
            if (($item['extnId'] ?? null) === self::OID) {
                $extension = $item;
                break;
            }
        }
        if ($extension === null) {
            throw new AttestationException('Không có extension attestation');
        }

        try {
            return $this->parseKeyDescription($extension['extnValue']);
        } catch (AttestationException $e) {
            throw $e;
        } catch (Throwable) {
            throw new AttestationException('Extension attestation sai cấu trúc');
        }
    }

    private function parseKeyDescription(string $der): array
    {
        $description = ASN1::decodeBER($der)[0]['content'] ?? null;
        if (! is_array($description) || count($description) < 8) {
            throw new AttestationException('Extension attestation sai cấu trúc');
        }

        $applicationId = $this->findTag($description[6]['content'], self::TAG_APPLICATION_ID);
        if ($applicationId === null) {
            throw new AttestationException('Thiếu attestationApplicationId');
        }

        // Ben trong [709] la 1 OCTET STRING chua DER -> decode lan nua
        $app = ASN1::decodeBER($applicationId['content'][0]['content'])[0]['content'];
        $package = $app[0]['content'][0]['content'][0]['content'] ?? null;
        if (! is_string($package) || $package === '') {
            throw new AttestationException('Không đọc được package name');
        }

        // Khong co [704] -> null (verifier quyet dinh tu choi hay khong)
        $rootOfTrust = $this->findTag($description[7]['content'], self::TAG_ROOT_OF_TRUST)['content'][0]['content'] ?? null;

        return [
            'security_level' => (int) $description[1]['content']->toString(),
            'keymint_security_level' => (int) $description[3]['content']->toString(),
            'challenge' => $description[4]['content'],
            'package' => $package,
            'digests' => array_map(fn ($digest) => bin2hex($digest['content']), $app[1]['content']),
            'device_locked' => is_array($rootOfTrust) ? (bool) $rootOfTrust[1]['content'] : null,
            'verified_boot_state' => is_array($rootOfTrust) ? (int) $rootOfTrust[2]['content']->toString() : null,
        ];
    }

    private function findTag(array $authorizationList, int $tag): ?array
    {
        foreach ($authorizationList as $element) {
            if (($element['constant'] ?? null) === $tag) {
                return $element;
            }
        }

        return null;
    }
}
