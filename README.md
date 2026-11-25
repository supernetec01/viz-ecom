# Viz Ecom

Website tuyển dụng theo thiết kế trong [docs/thiet-ke.md](docs/thiet-ke.md). Các task được triển khai tuần tự trên từng nhánh và PR. Trang chủ tiếng Việt tại `/` có đầu trang, chân trang và bố cục ưu tiên điện thoại (T14). Danh sách tại `/viec-lam` chỉ hiển thị tin published còn hạn, 12 tin/trang, thứ tự ngày đăng mới trước rồi ID giảm dần (T15). Từ T03, đăng nhập bằng phiên Laravel Fortify tại `/login`; `/app/login` chuyển về đó. Filament phục vụ giao diện quản trị tại `/app`.

**Trước khi chạy thực tế:** xem [checklist chuyển từ demo sang production](docs/trien-khai-thuc-te.md). Email local hiện chỉ ghi log; tài khoản mẫu, URL localhost và các chức năng chưa triển khai không được coi là đã sẵn sàng chạy thật. Mỗi task có phần giả lập hoặc cấu hình chỉ dùng local cần cập nhật checklist này, kèm cách kiểm tra trên môi trường thật.

## Trang công ty và SEO (T19–T20)

Tên công ty trên trang tin dẫn tới `/cong-ty/{id}`: giới thiệu, ngành hàng/sản phẩm, kênh bán hàng/video và tin còn nhận hồ sơ. Chỉ công ty đã xác minh/chưa xóa có trang công khai; link ngoài mở tab mới an toàn.

Meta/canonical/Open Graph có sẵn trong HTML, ảnh thương hiệu tại `/images/social-card.png` (1200×630); PNG đã commit nên Linux/CI không cần công cụ sinh ảnh. Nếu đổi thiết kế, có thể tái tạo trên Windows bằng `scripts/generate-social-card.ps1`. `/sitemap.xml` là index, chia tối đa 1.000 URL/file; robots.txt động dẫn tới đó. Tin trong sitemap chỉ published còn hạn của công ty đã xác minh. Đặt `APP_URL` đúng tên miền HTTPS chính thức; URL SEO lấy từ cấu hình.

**JobPosting mặc định tắt** (`SEO_JOB_POSTING_ENABLED=false`): form/luồng ứng tuyển T22–T24 chưa có. Chỉ bật sau khi nhận hồ sơ hoạt động thật, rồi cập nhật cache cấu hình. Kể cả bật, chỉ tin qua `canReceiveApplications()` và có địa chỉ đầy đủ mới xuất JSON-LD; không xuất ở danh sách/công ty hoặc tin không nhận. JSON thoát ký tự và có nonce CSP, không nới script-src. Không đoán kỳ tính lương/mã bưu chính: `baseSalary`/`postalCode` tùy chọn được bỏ qua.

Google Rich Results đã kiểm tra mã mẫu: 1 JobPosting hợp lệ, 2 cảnh báo tùy chọn trên. Chưa chứng minh Google crawl/index tên miền thật hoặc Zalo tải preview từ localhost. Kiểm tra HTTPS, ảnh/OG trên thiết bị khác và dán link vào Zalo nằm trong [checklist triển khai](docs/trien-khai-thuc-te.md), xem [báo cáo T20](docs/bao-cao-t20.md). Không cần API Zalo.

## Chia sẻ và nguồn ứng tuyển (T21)

Tin còn nhận hồ sơ có nút sao chép link kèm `nguon=chia-se`; không có JavaScript vẫn copy từ ô link. Có thể đặt nguồn khác khi phát link, ví dụ `?nguon=zalo-nhom_1`. Nguồn chỉ 1–64 ký tự chữ/số không dấu, gạch ngang/gạch dưới, chuẩn hóa chữ thường. Nguồn hợp lệ gần nhất lưu trong phiên; thiếu hoặc sai không ghi đè, không đổi nội dung trang. T24 sẽ đọc nguồn từ phiên khi lưu hồ sơ. Chưa gửi tin tự động hay tích hợp API Zalo.

## Nền database hồ sơ (T22)

Migration T22 thêm `files`, `applications` và khóa ghép bảo đảm hồ sơ thuộc công ty của tin. Mã hồ sơ là ULID; mỗi tin chỉ có một hồ sơ còn hiệu lực cho cùng SĐT chuẩn hóa. Có cờ/thời điểm đồng ý, nguồn, trạng thái và xóa mềm; xóa mềm tin/công ty không làm mất hồ sơ. Đây mới là cấu trúc dữ liệu, chưa có form hoặc dịch vụ lưu file. Chạy `php artisan migrate` khi cập nhật local; không dùng `migrate:fresh` trên DB có dữ liệu cần giữ. T23 trở đi mới xử lý file và T24 mới nhận hồ sơ.

## File CV riêng tư — T23 đang triển khai, chưa hoàn tất

Disk `candidate-files` tách khỏi disk local mặc định, lưu dưới `storage/app/candidate-files`, không symlink, không route trực tiếp. Dịch vụ kiểm tra kích thước thật tối đa 10 MiB, MIME từ nội dung và DOCX package; lưu UUID theo công ty/tin/ULID hồ sơ, hash SHA-256 và metadata chờ quét. Chưa có endpoint upload/download hoặc form T24.

DOCX sạch được nhận sau kiểm tra cấu trúc. DOC cũ/DOCM chưa hỗ trợ; DOCX có VBA/macro, OLE/ActiveX, quan hệ ngoài (kể cả hyperlink/mẫu từ xa), path nguy hiểm, ZIP mã hóa/bomb bị từ chối. Các giới hạn ZIP: tối đa 512 mục, 8 MiB/mục, tổng 32 MiB và tối đa 100:1 với mục trên 64 KiB; XML có giới hạn riêng. Đây không thay thế ClamAV T27.

**Đã triển khai qpdf + JSON v2 trong container cô lập** theo phương án được duyệt, không thêm Composer package. Nhận được object stream; kiểm tra cấu trúc đã mở rộng, từ chối mã hóa (cả mật khẩu rỗng), lỗi/timeout/OOM/output quá lớn. Chặn `JS`, `JavaScript`, `OpenAction`, `AA`, `Launch`, `GoToR`, `GoToE`, `SubmitForm`, `ImportData`, `XFA`, `RichMedia`/3D và các biến thể, `EmbeddedFile`/`EmbeddedFiles`/`Filespec`/`EF`. Giữ nguyên file gốc. File có marker bị chặn dù chỉ là hành động mở trang hoặc đính kèm vô hại; không tự rewrite để bỏ marker.

Mặc định `PDF_INSPECTION_ENABLED=false`; **giữ `PDF_PRODUCTION_ENABLED=false`** khi bộ mẫu/kiểm tra triển khai còn thiếu. Production còn yêu cầu rootless Docker và image ID cố định; thiếu điều kiện thì từ chối. PR T23 vẫn draft, chờ mẫu điện thoại/Canva không đính kèm; T24 chưa làm theo quyết định mới nhất. [Hướng dẫn Windows/Ubuntu, test thật, số đo và yêu cầu T48](docs/pdf-inspector-t48.md). Không tiếp nhận CV thật khi các task nhận hồ sơ, quét, phân quyền/audit/retention chưa hoàn tất. Trên Windows cần ACL phù hợp; CI Linux kiểm tra Unix private mode.

## Yêu cầu

- PHP 8.4 với các extension chuẩn Laravel, `pdo_pgsql`, `pgsql`, `mbstring`, `intl`, `fileinfo`, `curl`, `openssl`, `zip` và XML/DOM.
- Composer 2.
- PostgreSQL 18 đang chạy; có thể dùng PostgreSQL cài riêng hoặc Laragon có PostgreSQL.
- Git, Node.js 22.12+ và npm để build CSS công khai từ T14.

PHP 8.4 được chọn để chạy Pest 5. Phiên bản dependencies cụ thể nằm trong `composer.lock`; dùng `composer install`, không dùng `composer update` khi thiết lập máy mới.

## Chạy trên máy cá nhân

```sh
git clone https://github.com/supernetec01/viz-ecom.git
cd viz-ecom
git switch codex/t22-application-storage
composer install
npm ci
npm run build
```

Sao chép `.env.example` thành `.env`, `.env.testing.example` thành `.env.testing`. Trên PowerShell:

```powershell
Copy-Item .env.example .env
Copy-Item .env.testing.example .env.testing
```

Trên Linux/macOS dùng `cp` thay cho `Copy-Item`.

Tạo tài khoản database và hai database riêng bằng `psql` với tài khoản quản trị PostgreSQL:

```sql
CREATE ROLE viz_ecom LOGIN;
\password viz_ecom
CREATE DATABASE viz_ecom OWNER viz_ecom;
CREATE DATABASE viz_ecom_test OWNER viz_ecom;
```

Điền mật khẩu vừa đặt vào `DB_PASSWORD` trong cả hai file môi trường. Điều chỉnh `DB_HOST`, `DB_PORT` nếu PostgreSQL không dùng `127.0.0.1:5432`. `.env` dùng `DB_DATABASE=viz_ecom`; `.env.testing` dùng `DB_DATABASE=viz_ecom_test`. Không commit các file môi trường chứa mật khẩu.

```sh
php artisan key:generate
php artisan key:generate --env=testing
php artisan migrate --seed
php artisan serve
```

Mở `http://localhost:8000`; health endpoint là `/up`. `migrate:fresh` xóa toàn bộ bảng trong database đang cấu hình: chỉ dùng trên database phát triển/test.

Mở `http://localhost:8000/viec-lam` để kiểm tra danh sách, lọc T16 và tìm kiếm T17; bấm tiêu đề để mở chi tiết T18. Nếu danh sách trống, tin cần Gửi đăng/duyệt thành published, còn hạn và thuộc công ty đã xác minh. Draft/pending/paused/closed/expired và tin vừa quá hạn không nằm trong danh sách. Link chi tiết paused/expired vẫn 200 có thông báo; closed chỉ thông tin tối thiểu, không mô tả/yêu cầu/quyền lợi/lương. Chưa có form/POST nhận hồ sơ; tên công ty dạng chữ chờ T19 gắn link. Xem báo cáo [T15](docs/bao-cao-t15.md), [T16](docs/bao-cao-t16.md), [T17](docs/bao-cao-t17.md), [T18](docs/bao-cao-t18.md) và [CODEMAP](docs/CODEMAP.md).

T17: nhập `ke toan` để tìm tiêu đề “kế toán”, `Nguyen` để tìm tên công ty “Nguyễn”. Từ khóa tối đa 200 ký tự, tìm cả cụm từ trong tiêu đề hoặc tên công ty; bộ lọc cùng URL được giữ khi phân trang/Back. Khi nâng cấp chạy `php artisan migrate` trước mã mới: migration thêm unaccent/pg_trgm, cột tìm kiếm chuẩn hóa, trigger và GIN index. PostgreSQL cần UTF-8 và module tương ứng; role migration cần CREATE trên database hoặc DBA cài extension trước vào public. Nội dung gốc không bị đổi. Không dùng `migrate:fresh` cho DB đang sử dụng.

Từ T02, seeder tạo ba tài khoản local/testing: `admin@viz-ecom.test`, `staff@viz-ecom.test`, `employer@viz-ecom.test`. Điền `SEED_SAMPLE_PASSWORD` trong `.env` trước lần seed đầu để tự chọn mật khẩu mẫu (khuyến nghị ít nhất 12 ký tự); để trống thì mật khẩu ngẫu nhiên không được in ra log. Seed lại không đổi mật khẩu hoặc tạo trùng tài khoản. Không tạo tài khoản mẫu ở production.

Từ T03, admin/nhân viên phải thiết lập và xác nhận 2FA bằng ứng dụng Authenticator trước khi mở bảng điều khiển. Trang `/bao-mat` yêu cầu xác nhận lại mật khẩu, hiển thị mã QR và mã khôi phục. Nhà tuyển dụng có thể bật 2FA tùy chọn. Lưu mã khôi phục ở nơi an toàn. Link đặt lại mật khẩu dùng một lần, hết hạn 24 giờ. Môi trường thật cần cấu hình SMTP; local mặc định ghi email vào `storage/logs/laravel.log`, chỉ dùng dữ liệu thử. Không công khai log này. Tài nguyên Filament được xuất khi `composer install`. Fortify là phần mềm miễn phí theo giấy phép MIT.

Từ T09, admin/nhân viên mở `/app/cong-ty`, sửa công ty được phân công rồi dùng bảng **Tài khoản công ty** để mời, gửi lại lời mời hoặc khóa/mở khóa tài khoản. Email mời chỉ chứa liên kết đặt mật khẩu dùng một lần trong 24 giờ. Khóa tài khoản thu hồi phiên đăng nhập và liên kết đặt mật khẩu hiện có.

T10 có dữ liệu/model tin tuyển dụng; T11 thêm giao diện tại `/app/tin-tuyen-dung`: tạo/sửa bản nháp, xem trước, nhân bản, tạm dừng/tiếp tục, đóng tin. Chỉ công ty đã được admin xác minh mới tạo được tin. Lương là số nguyên VND, khoảng lương có thể để trống một hoặc cả hai đầu; không âm và tối thiểu không lớn hơn tối đa. Slug được giữ khi sửa tiêu đề và không tái dùng sau xóa mềm. Bảng hàng đợi đổi thành `queue_jobs` bằng migration mới, giữ dữ liệu cũ. Nếu đã đặt `DB_QUEUE_TABLE=jobs` trong môi trường riêng, đổi thành `queue_jobs` khi nâng cấp.

## CSP và ngoại lệ Filament

Người dùng duyệt ngoại lệ ngày 21/11/2026 vì phiên bản Filament hiện tại sinh biểu thức JavaScript chưa tương thích runtime CSP-safe của Livewire. `SecurityHeaders` gọi `Vite::useCspNonce()` của Laravel trước khi render: nonce ngẫu nhiên mới cho mỗi yêu cầu, được Filament/Livewire gắn vào script và khớp header. Không thêm gói Composer và không sửa vendor.

| Phạm vi | Chính sách script |
| --- | --- |
| `/app`, `/app/*`, trừ `/app/login` | `script-src 'self' 'nonce-…' 'unsafe-eval'`, không `unsafe-inline` |
| Trang công khai, đăng nhập, các đường dẫn khác | `script-src 'self' 'nonce-…'`, không `unsafe-eval` hoặc `unsafe-inline` |
| Profile file riêng tư `csp.private-file` | `default-src 'none'; script-src 'none'; …; sandbox`, không eval/inline/nonce, ưu tiên hơn profile `/app` |

`style-src` vẫn cho inline style phục vụ Filament; ngoại lệ này không áp dụng cho script. HTML quản trị không cache (`no-store, private`). Runtime chuẩn được chèn ở hook footer của panel; trang khác vẫn dùng runtime CSP-safe. Endpoint asset Livewire trả build theo version ID (có/không hậu tố `-csp`), nhưng header của asset không được cấp `unsafe-eval`. Cấu hình runtime được phục hồi sau middleware để không rò sang yêu cầu khác.

Điều kiện bỏ ngoại lệ: khi nâng cấp Filament và xác minh tương thích runtime CSP-safe của Livewire, bỏ `unsafe-eval` của panel, chuyển panel về runtime an toàn, sửa `ContentSecurityPolicyTest` cùng lúc rồi kiểm tra đủ thao tác trên trình duyệt. Mỗi lần đổi CSP phải cập nhật các test tương ứng.

T33 trở đi phải gắn middleware `csp.private-file` cho **mọi** endpoint xem/tải CV và portfolio, kể cả endpoint nằm dưới `/app`; kiểm tra lại quyền và audit vẫn bắt buộc. Hiện mới có profile CSP và test header, chưa tạo endpoint CV. Khi có luồng PDF/xem/tải thật phải kiểm tra chức năng với sandbox; mọi điều chỉnh policy phải kèm test và giữ nguyên việc chặn script.

T11 đã được kiểm tra thao tác thật sau sửa CSP: mở menu, tạo/sửa/xem trước/nhân bản và tạm dừng. T12 cung cấp backend `SubmitJob`: kiểm tra quyền, quét tin draft rồi chuyển published nếu sạch hoặc pending nếu cần duyệt. T13 nối nút **Gửi đăng** tại trang xem trước và menu thao tác tin; chọn ngày hết hạn còn hiệu lực khi gửi.

**T13 — Duyệt tin:** admin mở `/app/duyet-tin` để xem các tin pending, xem trước và lý do bị giữ (gồm URL ngoài cụ thể), duyệt đăng hoặc từ chối kèm lý do bắt buộc. Từ chối trả tin về bản nháp để sửa/gửi lại. Nhân viên phụ trách còn hoạt động nhận thông báo ở chuông Filament; mở tin để xem lý do theo quyền hiện tại. Đây là thông báo lưu trong database, không cần SMTP hay queue worker; khác với email lời mời T09 hiện vẫn cần cấu hình gửi thật. Nâng cấp bằng `php artisan migrate`; không dùng `migrate:fresh` với dữ liệu đang sử dụng. Xem [báo cáo T13](docs/bao-cao-t13.md) để kiểm tra theo vai trò.

Địa chỉ làm việc theo từng tin gồm **Tỉnh/Thành phố → Xã/Phường/Đặc khu → Số nhà, đường / thôn, tổ dân phố**. Hai ô sau nhập văn bản theo địa chỉ mới, bắt buộc khi tạo/sửa bản nháp và gửi đăng; chưa có danh mục chọn xã/phường hoặc xác minh địa chỉ tự động. Không yêu cầu quận/huyện. Đổi tỉnh trên form sẽ xóa hai ô để nhập lại. Xem trước hiển thị đủ địa chỉ, nhân bản giữ địa chỉ của tin gốc; địa chỉ trụ sở công ty là dữ liệu riêng.

Nâng cấp bằng `php artisan migrate` (không dùng `migrate:fresh`). Migration mới giữ nguyên tin cũ với hai trường địa chỉ null; xem trước báo thiếu, cần bổ sung trong bản nháp trước lưu/gửi/nhân bản. Tin cũ đã đăng vẫn tạm dừng/đóng/hết hạn được. Trang công khai cho ứng viên ở T18 chưa xây; phải dùng `Job::workplaceAddress()` khi triển khai, tránh chỉ hiển thị tỉnh. Chi tiết và kiểm tra: [báo cáo địa chỉ làm việc](docs/dia-chi-lam-viec.md).

Đặt `APP_URL` đúng URL chuẩn của website để T12 nhận diện link nội bộ. So sánh hostname chính xác, không tự cho phép www hoặc tên miền con khác. Kênh công ty tách riêng không bị quét. Bộ quét dùng chuẩn hóa Unicode, mẫu SĐT/email/từ khóa và tên miền; đây là heuristic, cần bổ sung test nếu phát hiện cách viết né lọc mới. Server không mở các link được quét.

Trang chào mặc định chạy được mà chưa build Vite. Nếu phát triển CSS/JavaScript, cài dependencies và build trước khi chạy:

```sh
npm ci
npm run build
```

## Kiểm tra trước khi tạo pull request

### T24 — thử form ứng tuyển local

Mở một tin đang đăng, còn hạn và thuộc công ty đã xác minh; form ở cuối trang. Họ tên, SĐT và đồng ý xử lý dữ liệu bắt buộc; email, CV và link portfolio tùy chọn. SĐT 0/+84/84 được chuẩn hóa; nộp trùng cùng tin bị chặn. Thành công báo “Đã nhận hồ sơ”; khi lỗi giữ dữ liệu chữ và yêu cầu chọn lại file/xác minh captcha. CV pending ở disk riêng tư, chưa có giao diện đọc hoặc URL xem/tải. File portfolio thuộc T25.

Form dùng **Cloudflare Turnstile**. Local/testing mặc định khóa thử nghiệm chính thức, có ghi demo trên form, vẫn gọi Siteverify ở server; không bỏ qua xác minh. Khóa demo có thể phát cùng token cố định: cơ chế một lần dùng sẽ từ chối token lặp trong 10 phút. Để kiểm tra nhiều lượt thực tế, cấu hình widget phát token thật cho tên miền phát triển thay vì tắt chống replay. Test tự động dùng `Http::fake` và token mẫu riêng. Xem [khóa thử nghiệm](https://developers.cloudflare.com/turnstile/troubleshooting/testing/) và [Siteverify](https://developers.cloudflare.com/turnstile/get-started/server-side-validation/).

Production bắt buộc đặt `TURNSTILE_SITE_KEY`, `TURNSTILE_SECRET_KEY` thật và `APP_URL` đúng hostname HTTPS, `APP_DEBUG=false`; secret chỉ ở môi trường, không trong HTML. Thiếu cấu hình, khóa demo, hostname/action sai, dịch vụ lỗi hoặc timeout 5s đều chặn gửi; không log token. CSP chỉ trang có form thêm `https://challenges.cloudflare.com` cho script/frame, giữ nonce, không script inline/eval. Tham chiếu [CSP Turnstile](https://developers.cloudflare.com/turnstile/reference/content-security-policy/). Chính sách tại `/chinh-sach-du-lieu` nêu dịch vụ này.

**Giữ `PDF_PRODUCTION_ENABLED=false`.** T23 còn thiếu corpus Canva sạch/điện thoại; người dùng cho phép viết tiếp T24–T25 ngày 25/11, không đồng nghĩa T23 hoàn tất hoặc cho mở production PDF. PDF local cũng mặc định tắt; chỉ bật sau chuẩn bị runner theo hướng dẫn T23. DOCX vẫn kiểm tra cấu trúc. Chưa mở nhận hồ sơ thật trước khi hoàn thành AV, quyền xem/audit và lịch xóa dữ liệu ở các task sau. Không tự gửi email báo hồ sơ mới ở T24.

Sau pull chạy `php artisan migrate` và `npm run build`; không `migrate:fresh` trên DB local đang dùng. Kết quả và giới hạn ở [báo cáo T24](docs/bao-cao-t24.md).

```sh
composer validate --strict
composer test
composer lint
```

- `composer test`: Pest chạy trên PostgreSQL thật, bao gồm đăng nhập/2FA, phân quyền công ty, thao tác Filament, lời mời tài khoản, dữ liệu/máy trạng thái tin, quét nội dung và gửi đăng. `ContentScannerTest` chạy độc lập không cần database; `JobSubmissionTest` kiểm tra tích hợp và quyền trên PostgreSQL.
- `composer lint`: Pint kiểm tra định dạng, Larastan phân tích tĩnh ở mức 5. Sửa định dạng bằng `vendor/bin/pint` nếu cần.
- `phpunit.xml` buộc database test là `viz_ecom_test` và bỏ `DB_URL`, tránh vô tình dùng database phát triển. Test migration làm mới database test; không trỏ cấu hình test vào dữ liệu thật.
- GitHub Actions chạy mỗi lần push và mở/cập nhật PR: PostgreSQL 18, PHP 8.4, cài dependencies từ lockfile, migration + seed, Pest, Pint và Larastan.

Đọc [AGENTS.md](AGENTS.md) trước khi làm task. Mỗi task một nhánh và một PR; chờ xem lại trước khi chuyển task kế tiếp.

## T25 — Portfolio và giới hạn upload

Form nhận **một link HTTP/HTTPS hoặc một file PDF/JPG/JPEG/PNG**, cũng có thể bỏ trống. Không gửi link và file cùng lúc. Server không tự mở link; file lưu UUID ở disk private, trạng thái pending, chưa có xem/tải. Ảnh kiểm tra MIME/nội dung, kích thước header và cấu trúc PNG CRC/chunk hoặc JPEG marker; giới hạn 16.384 px mỗi chiều, 40 triệu pixel. Đây không phải giải mã toàn bộ pixel hoặc antivirus. PDF dùng lại qpdf T23; production vẫn tắt.

PHP phải đặt `upload_max_filesize=10M`, `post_max_size=24M` (hai file 10 MiB và dữ liệu form), `upload_tmp_dir` riêng ngoài web root và có quyền ghi của user chạy PHP. Kiểm tra file ini thật bằng `php --ini`, đổi cấu hình rồi khởi động lại server/PHP-FPM. Máy local hiện dùng `.tools/php/php.ini`, upload tạm ở `.runtime/php-upload`; đường dẫn này chỉ dành cho máy hiện tại, không sao chép lên server. Nginx dùng `client_max_body_size 24m`; PHP/Laravel vẫn kiểm tra tối đa 10 MiB cho từng file. Cấu hình trang lỗi 413 tương ứng ở Nginx khi triển khai; ứng dụng đã có 413 tiếng Việt nếu request tới PHP. Xác nhận bằng upload thật, không chỉ dựa vào UploadedFile::fake.

Browser local đã gửi PNG tổng hợp đúng 10 MiB qua form và Turnstile demo sau khi sửa giới hạn/thư mục tạm. Chỉ dữ liệu mẫu, không CV thật. Test DB/ảnh/PDF/rollback và giới hạn được ghi tại [báo cáo T25](docs/bao-cao-t25.md). T26 vẫn giữ lệnh dọn mồ côi và test lỗi nhiều tầng; T27 mới quét AV/chuyển đổi, T33 mới xem/tải có policy/audit/CSP.
