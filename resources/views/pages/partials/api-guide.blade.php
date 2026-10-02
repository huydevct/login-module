@php
    // Gia tri lay tu config dang chay de huong dan luon khop server.
    $prefix = '/'.trim((string) config('login.api.prefix'), '/');
    $attestation = (bool) config('login.attestation.enabled');
    $tokenDays = (string) (float) config('login.api.token_ttl_days');
    $secretTtl = (int) config('login.api.secret_ttl');
    $challengeTtl = (int) config('login.attestation.challenge_ttl');
    $window = (int) config('login.attestation.timestamp_window');
    $authAlias = (string) config('login.api.middleware_alias');
    $signedAlias = (string) config('login.attestation.middleware_alias');

    // Code Kotlin de trong nowdoc: PHP/Blade khong dong vao ${...} va {{ cua Kotlin.
    $secretCode = <<<'KOTLIN'
import android.provider.Settings
import android.util.Base64
import java.security.SecureRandom
import javax.crypto.Cipher
import javax.crypto.spec.IvParameterSpec
import javax.crypto.spec.SecretKeySpec
import org.json.JSONObject

/** secret = base64(iv + AES-256-CBC(json)), khoá = JWT_OPENSSL_DEVICE_SECRET (cấu hình trong app). */
fun buildDeviceSecret(context: Context, deviceSecret: String): String {
    val json = JSONObject()
        .put("client_id", Settings.Secure.getString(context.contentResolver, Settings.Secure.ANDROID_ID))
        .put("platform", "android")                      // iOS: "ios"
        .put("package_id", context.packageName)
        .put("time", System.currentTimeMillis() / 1000)  // epoch GIÂY
        .toString()

    // Giống PHP openssl_encrypt: khoá > 32 byte bị cắt, < 32 byte đệm \0
    val key = SecretKeySpec(deviceSecret.toByteArray(Charsets.UTF_8).copyOf(32), "AES")
    val iv = ByteArray(16).also { SecureRandom().nextBytes(it) }
    val cipher = Cipher.getInstance("AES/CBC/PKCS5Padding")
    cipher.init(Cipher.ENCRYPT_MODE, key, IvParameterSpec(iv))
    val encrypted = cipher.doFinal(json.toByteArray(Charsets.UTF_8))

    return Base64.encodeToString(iv + encrypted, Base64.NO_WRAP)
}

// POST __PREFIX__/add-device  {"secret": buildDeviceSecret(...)}
// -> lưu data.access_token, data.device.id, data.device.app_id
KOTLIN;

    $attestCode = <<<'KOTLIN'
import android.os.Build
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.security.keystore.StrongBoxUnavailableException
import android.util.Base64
import java.security.KeyPairGenerator
import java.security.KeyStore
import java.security.spec.ECGenParameterSpec

const val KEY_ALIAS = "login_device_key"

/** Gọi khi chưa có key (cài mới, xoá data) hoặc server trả device_not_attested. */
suspend fun attestDevice(api: LoginApi) {
    // 1. Xin challenge (Bearer JWT). Challenge dùng 1 lần, sống __CHALLENGE_TTL__ giây.
    val challenge = Base64.decode(api.attestChallenge().data.challenge, Base64.DEFAULT)

    // 2. Tạo key EC trong Keystore, gắn challenge. Ưu tiên StrongBox, không có thì TEE.
    val keyStore = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
    keyStore.deleteEntry(KEY_ALIAS)
    fun generate(strongBox: Boolean) {
        val spec = KeyGenParameterSpec.Builder(KEY_ALIAS, KeyProperties.PURPOSE_SIGN)
            .setAlgorithmParameterSpec(ECGenParameterSpec("secp256r1"))
            .setDigests(KeyProperties.DIGEST_SHA256)
            .setAttestationChallenge(challenge)
            .apply { if (Build.VERSION.SDK_INT >= 28) setIsStrongBoxBacked(strongBox) }
            .build()
        KeyPairGenerator.getInstance(KeyProperties.KEY_ALGORITHM_EC, "AndroidKeyStore")
            .apply { initialize(spec) }
            .generateKeyPair()
    }
    try {
        generate(strongBox = true)
    } catch (e: StrongBoxUnavailableException) {
        generate(strongBox = false)
    }

    // 3. Gửi chain (DER base64, leaf đứng đầu) -> server lưu public key.
    val chain = keyStore.getCertificateChain(KEY_ALIAS)
        .map { Base64.encodeToString(it.encoded, Base64.NO_WRAP) }
    api.attestRegister(AttestRegisterBody(chain))   // 200: data.security_level = TEE | StrongBox
}
KOTLIN;

    $signCode = <<<'KOTLIN'
import android.util.Base64
import java.security.KeyStore
import java.security.Signature
import java.util.UUID
import okhttp3.Interceptor
import okhttp3.Response
import okio.Buffer

/** Gắn vào OkHttpClient của các API cần __SIGNED_ALIAS__ (thêm sau interceptor gắn Bearer JWT). */
class SignatureInterceptor(private val session: Session) : Interceptor {
    override fun intercept(chain: Interceptor.Chain): Response {
        val request = chain.request()
        val body = Buffer().also { request.body?.writeTo(it) }  // đúng các byte sẽ gửi đi
        val ts = (System.currentTimeMillis() / 1000).toString()      // GIÂY, không phải mili-giây
        val nonce = UUID.randomUUID().toString().replace("-", "")  // mới cho MỖI lần gửi

        val payload = listOf(
            request.method,                    // POST
            request.url.encodedPath,           // /api/coins/add — dạng đã encode, không có / cuối
            request.url.encodedQuery ?: "",    // query gốc, rỗng nếu không có
            ts,
            nonce,
            session.deviceId.toString(),       // data.device.id từ add-device
            session.appId.toString(),          // data.device.app_id từ add-device
            body.sha256().hex(),               // SHA-256 hex chữ thường của body (body rỗng vẫn băm)
        ).joinToString("\n")                   // KHÔNG có \n ở cuối

        val keyStore = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        val privateKey = (keyStore.getEntry(KEY_ALIAS, null) as KeyStore.PrivateKeyEntry).privateKey
        val signature = Signature.getInstance("SHA256withECDSA").run {
            initSign(privateKey)
            update(payload.toByteArray(Charsets.UTF_8))
            sign()
        }

        return chain.proceed(
            request.newBuilder()
                .header("X-Timestamp", ts)
                .header("X-Nonce", nonce)
                .header("X-Signature", Base64.encodeToString(signature, Base64.NO_WRAP))
                .build()
        )
    }
}
KOTLIN;

    $replace = ['__PREFIX__' => $prefix, '__CHALLENGE_TTL__' => $challengeTtl, '__SIGNED_ALIAS__' => $signedAlias];
    $secretCode = strtr($secretCode, $replace);
    $attestCode = strtr($attestCode, $replace);
    $signCode = strtr($signCode, $replace);
@endphp

<div class="api-guide">
    <h4 class="mb-3">1. Tổng quan luồng</h4>
    <ol>
        <li><code>POST {{ $prefix }}/add-device</code> với <code>secret</code> mã hoá → nhận JWT (<code>access_token</code>), <code>device.id</code>, <code>device.app_id</code>.</li>
        <li>Mọi API cần đăng nhập thiết bị gửi header <code>Authorization: Bearer &lt;access_token&gt;</code> (middleware <code>{{ $authAlias }}</code>).</li>
        @if ($attestation)
            <li><strong>Android:</strong> sau add-device, nếu chưa có key thì attest <strong>1 lần</strong>: <code>{{ $prefix }}/attest/challenge</code> → tạo key trong Keystore → <code>{{ $prefix }}/attest/register</code>.</li>
            <li>API của project bảo vệ bằng <code>{{ $signedAlias }}</code>: mỗi request ký bằng key trong Keystore và gửi thêm <code>X-Timestamp</code>, <code>X-Nonce</code>, <code>X-Signature</code>.</li>
        @endif
    </ol>
    <pre class="bg-light border rounded p-3 small mb-4"><code>{{ "add-device ──► JWT".($attestation ? "\n   └─(Android, 1 lần) attest/challenge ──► tạo key Keystore ──► attest/register\nMỗi request cần bảo vệ: Bearer JWT + X-Timestamp + X-Nonce + X-Signature" : "\nMỗi request: Authorization: Bearer <access_token>") }}</code></pre>

    <h4 class="mb-3">2. Tạo <code>secret</code> cho add-device</h4>
    <p>
        <code>secret = base64(iv 16 byte + AES-256-CBC(json))</code>, khoá là <code>JWT_OPENSSL_DEVICE_SECRET</code> của server (app giữ cùng khoá).
        <code>json</code> gồm <code>client_id</code> (định danh ổn định của máy, vd <code>ANDROID_ID</code>), <code>platform</code> (<code>android</code>/<code>ios</code>),
        <code>package_id</code>, <code>time</code> (epoch giây — secret hết hạn sau {{ $secretTtl }} giây khi server tắt debug).
        Cùng <code>client_id</code> + package luôn trả về cùng thiết bị.
    </p>
    <pre class="bg-light border rounded p-3 small mb-4"><code>{{ $secretCode }}</code></pre>

    <h4 class="mb-3">3. Lưu và làm mới JWT</h4>
    <ul class="mb-4">
        <li>Lưu <code>access_token</code>, <code>device.id</code>, <code>device.app_id</code> (2 số sau dùng để ký request).</li>
        <li>JWT sống <strong>{{ $tokenDays }} ngày</strong>. Gặp <code>401</code> với <code>status</code> = <code>expire</code> / <code>not_found_token</code> / <code>not_found_id</code>: gọi lại add-device (cùng <code>client_id</code> → cùng thiết bị, key đã attest vẫn giữ, <strong>không cần attest lại</strong>) rồi gửi lại request.</li>
        <li><code>403 Account is deactivated</code>: thiết bị bị khoá — dừng, không retry.</li>
    </ul>

    @if ($attestation)
        <h4 class="mb-3">4. Attest thiết bị (Android)</h4>
        <p>
            Chạy khi chưa có key <code>KEY_ALIAS</code> trong Keystore (cài mới, xoá data) hoặc server trả <code>device_not_attested</code>.
            Đăng ký lại sẽ thay key cũ. iOS không có bước này.
        </p>
        <pre class="bg-light border rounded p-3 small mb-3"><code>{{ $attestCode }}</code></pre>
        <ul class="mb-4">
            <li><code>400 Challenge không tồn tại hoặc đã hết hạn</code>: challenge chỉ dùng 1 lần — xin challenge mới, tạo lại key.</li>
            <li><code>403 Attestation: &lt;lý do&gt;</code>: chain không hợp lệ (sai package, sai chữ ký app — build không phải bản phát hành, máy mở khoá bootloader…). Ghi log lý do, <strong>không retry liên tục</strong>.</li>
        </ul>

        <h4 class="mb-3">5. Ký từng request ({{ $signedAlias }})</h4>
        <p>Chuỗi được ký gồm 8 dòng nối bằng <code>\n</code>, <strong>không</strong> có <code>\n</code> cuối:</p>
        <pre class="bg-light border rounded p-3 small mb-3"><code>METHOD
/PATH
QUERY
TIMESTAMP
NONCE
DEVICE_ID
APP_ID
SHA256_HEX(body)</code></pre>
        <pre class="bg-light border rounded p-3 small mb-3"><code>{{ $signCode }}</code></pre>
        <ul class="mb-4">
            <li><strong>Path</strong> tính từ gốc ứng dụng Laravel, dạng đã encode (<code>encodedPath</code>, không dùng <code>path</code>), không có <code>/</code> cuối.</li>
            <li><strong>Timestamp</strong> lệch quá <strong>{{ $window }} giây</strong> so với server sẽ bị từ chối.</li>
            <li><strong>Body</strong> gửi JSON hoặc nội dung file (<code>application/octet-stream</code>); <strong>không</strong> dùng <code>multipart/form-data</code>. File lớn: băm theo stream (<code>MessageDigest.update()</code> từng đoạn), đừng đọc cả file vào RAM.</li>
            <li><strong>Retry</strong>: mỗi lần gửi lại phải ký lại với timestamp và nonce mới.</li>
        </ul>
    @endif

    <h4 class="mb-3">{{ $attestation ? '6' : '4' }}. Mã lỗi → app cần làm gì</h4>
    <div class="table-responsive">
        <table class="table table-sm table-bordered align-middle">
            <thead class="table-light">
                <tr><th>HTTP</th><th><code>status</code> / message</th><th>Nguyên nhân</th><th>App làm gì</th></tr>
            </thead>
            <tbody>
                <tr><td>401</td><td><code>not_found_token</code>, <code>expire</code>, <code>not_found_id</code></td><td>Thiếu / sai / hết hạn JWT</td><td>Gọi lại add-device rồi gửi lại</td></tr>
                <tr><td>403</td><td><code>Account is deactivated</code></td><td>Thiết bị bị khoá</td><td>Dừng, báo người dùng</td></tr>
                @if ($attestation)
                    <tr><td>400</td><td><code>Challenge không tồn tại hoặc đã hết hạn</code></td><td>Challenge đã dùng / hết hạn</td><td>Xin challenge mới, tạo lại key</td></tr>
                    <tr><td>403</td><td><code>Attestation: …</code></td><td>Chain không hợp lệ</td><td>Ghi log lý do, không retry liên tục</td></tr>
                    <tr><td>400</td><td><code>missing_signature_header</code></td><td>Thiếu / sai header ký</td><td>Lỗi code app — kiểm tra interceptor</td></tr>
                    <tr><td>400</td><td><code>unsupported_content_type</code></td><td>Body <code>multipart/*</code></td><td>Gửi JSON hoặc <code>application/octet-stream</code></td></tr>
                    <tr><td>401</td><td><code>request_expired</code></td><td>Giờ máy lệch quá {{ $window }} giây</td><td>Bù lệch giờ (theo header <code>Date</code> của response) rồi ký lại</td></tr>
                    <tr><td>403</td><td><code>device_not_attested</code></td><td>Server chưa có public key của thiết bị</td><td>Chạy attest rồi gửi lại</td></tr>
                    <tr><td>401</td><td><code>invalid_signature</code></td><td>Chuỗi ký khác server (path/query/body/method) hoặc key đã đổi (cài lại app)</td><td>Attest lại 1 lần rồi gửi lại; vẫn lỗi → kiểm tra cách dựng chuỗi ký</td></tr>
                    <tr><td>401</td><td><code>replayed_request</code></td><td>Nonce đã dùng</td><td>Ký lại với nonce mới</td></tr>
                @endif
            </tbody>
        </table>
    </div>
</div>
