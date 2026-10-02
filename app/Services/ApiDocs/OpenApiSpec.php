<?php

namespace Modules\Login\Services\ApiDocs;

/**
 * Sinh tai lieu OpenAPI 3 cho cac API cua module theo config hien tai (prefix, attestation bat/tat).
 * Khong khai bao "servers": Swagger UI goi API tren cung domain voi trang docs.
 */
class OpenApiSpec
{
    public function build(): array
    {
        $prefix = '/'.trim((string) config('login.api.prefix'), '/');
        $attestation = (bool) config('login.attestation.enabled');

        $paths = [$prefix.'/add-device' => ['post' => $this->addDevice()]];
        if ($attestation) {
            $paths[$prefix.'/attest/challenge'] = ['post' => $this->attestChallenge()];
            $paths[$prefix.'/attest/register'] = ['post' => $this->attestRegister()];
        }

        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => config('login.cms.title').' — Login module API',
                'version' => '1.0.0',
                'description' => $this->description($prefix, $attestation),
            ],
            'tags' => array_values(array_filter([
                ['name' => 'Device auth', 'description' => 'Đăng ký thiết bị và cấp JWT'],
                $attestation ? ['name' => 'Attestation', 'description' => 'Đăng ký key Android Keystore (Key Attestation)'] : null,
            ])),
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    'deviceJwt' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'JWT',
                        'description' => '`access_token` trả về từ add-device (middleware `'.config('login.api.middleware_alias').'`).',
                    ],
                ],
                'schemas' => [
                    'AuthError' => [
                        'type' => 'object',
                        'properties' => [
                            'code' => ['type' => 'integer', 'example' => 401],
                            'message' => ['type' => 'string', 'example' => 'Not auth,[ApiAuthenticate]'],
                            'status' => ['type' => 'string', 'enum' => ['not_found_token', 'expire', 'not_found_id']],
                        ],
                    ],
                    'Message' => $this->envelope(['message' => ['type' => 'string']]),
                ],
            ],
        ];
    }

    private function addDevice(): array
    {
        return [
            'tags' => ['Device auth'],
            'summary' => 'Đăng ký thiết bị, nhận JWT',
            'description' => "`secret` = base64(iv + AES-256-CBC(json)) với khoá `JWT_OPENSSL_DEVICE_SECRET`; json gồm "
                .'`client_id`, `platform` (`android`/`ios`), `package_id`, `time` (epoch giây, hết hạn sau '
                .config('login.api.secret_ttl').' giây khi APP_DEBUG=false). Tạo secret để thử: `php artisan login:create-device-token`. '
                .'Gọi lại với cùng `client_id` (không phân biệt hoa/thường, bỏ khoảng trắng đầu/cuối) trả về cùng thiết bị; cùng `client_id` ở app khác là thiết bị khác.',
            'requestBody' => $this->jsonBody(['secret' => ['type' => 'string', 'example' => 'base64...']], ['secret']),
            'responses' => [
                '200' => $this->jsonResponse('JWT của thiết bị', $this->envelope([
                    'access_token' => ['type' => 'string'],
                    'device' => ['type' => 'object', 'properties' => [
                        'id' => ['type' => 'integer'],
                        'name' => ['type' => 'string'],
                        'client_id' => ['type' => 'string'],
                        'app_id' => ['type' => 'integer'],
                    ]],
                ])),
                '401' => $this->ref('Secret sai / thiếu field / hết hạn (`Secret error!`, `Secret error: payload data error!`, `Secret error: Secret expire!`)', 'Message'),
                '422' => ['description' => 'Thiếu `secret`'],
            ],
        ];
    }

    private function attestChallenge(): array
    {
        return [
            'tags' => ['Attestation'],
            'summary' => 'Xin challenge để tạo key trong Keystore',
            'description' => 'Challenge 32 byte (base64), dùng 1 lần, sống '.config('login.attestation.challenge_ttl')
                .' giây. App truyền vào `setAttestationChallenge()` khi tạo key EC secp256r1.',
            'security' => [['deviceJwt' => []]],
            'responses' => [
                '200' => $this->jsonResponse('Challenge', $this->envelope(['challenge' => ['type' => 'string', 'format' => 'byte']])),
                '401' => $this->ref('JWT thiếu / sai / hết hạn', 'AuthError'),
            ],
        ];
    }

    private function attestRegister(): array
    {
        return [
            'tags' => ['Attestation'],
            'summary' => 'Gửi chain attestation, lưu public key của thiết bị',
            'description' => 'Chain lấy từ `KeyStore.getCertificateChain(alias)`, mỗi cert DER base64, **leaf đứng đầu**. '
                .'Server kiểm tra chain (chữ ký, root Google, thu hồi, challenge, phần cứng, boot state, package, chữ ký app). '
                .'Đăng ký lại sẽ thay public key cũ.',
            'security' => [['deviceJwt' => []]],
            'requestBody' => $this->jsonBody([
                'chain' => ['type' => 'array', 'minItems' => 2, 'maxItems' => 10, 'items' => ['type' => 'string', 'format' => 'byte']],
            ], ['chain']),
            'responses' => [
                '200' => $this->jsonResponse('Đã lưu public key', $this->envelope([
                    'security_level' => ['type' => 'string', 'enum' => ['TEE', 'StrongBox']],
                ])),
                '400' => $this->ref('`Challenge không tồn tại hoặc đã hết hạn` — xin challenge mới', 'Message'),
                '401' => $this->ref('JWT thiếu / sai / hết hạn', 'AuthError'),
                '403' => $this->ref('`Attestation: <lý do>` — chain không hợp lệ', 'Message'),
                '422' => ['description' => '`chain` thiếu hoặc sai kích thước'],
            ],
        ];
    }

    private function description(string $prefix, bool $attestation): string
    {
        $text = "API của module Login. Bấm **Authorize** và dán `access_token` từ `POST {$prefix}/add-device` "
            .'để thử các API cần JWT.';
        if (! $attestation) {
            return $text;
        }

        $alias = config('login.attestation.middleware_alias');

        return $text."\n\n### Ký request (`{$alias}`)\n"
            ."Route của project dùng middleware `{$alias}` (sau `".config('login.api.middleware_alias').'`) yêu cầu thêm 3 header: '
            .'`X-Timestamp` (epoch giây), `X-Nonce` (1–64 ký tự, mỗi request một giá trị), `X-Signature` '
            .'(base64 chữ ký `SHA256withECDSA` bằng key trong Keystore). Chuỗi được ký, nối bằng `\n`, không có `\n` cuối:'
            ."\n\n```\nMETHOD\n/PATH\nQUERY\nTIMESTAMP\nNONCE\nDEVICE_ID\nAPP_ID\nSHA256_HEX(body)\n```\n\n"
            .'Swagger không ký được bằng Keystore nên không thử trực tiếp các route này ở đây. Chi tiết và mã lỗi: README › Ký từng request.';
    }

    private function envelope(array $dataProperties): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'data' => ['type' => 'object', 'properties' => $dataProperties],
                'status' => ['type' => 'integer', 'example' => 200],
            ],
        ];
    }

    private function jsonBody(array $properties, array $required): array
    {
        return [
            'required' => true,
            'content' => ['application/json' => ['schema' => [
                'type' => 'object',
                'required' => $required,
                'properties' => $properties,
            ]]],
        ];
    }

    private function jsonResponse(string $description, array $schema): array
    {
        return ['description' => $description, 'content' => ['application/json' => ['schema' => $schema]]];
    }

    private function ref(string $description, string $schema): array
    {
        return $this->jsonResponse($description, ['$ref' => '#/components/schemas/'.$schema]);
    }
}
