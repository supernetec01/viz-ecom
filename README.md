# Viz Ecom

Website tuyển dụng theo thiết kế trong [docs/thiet-ke.md](docs/thiet-ke.md). Các task được triển khai tuần tự trên từng nhánh và PR. Trang `/` hiện là trang chào Laravel. Từ T03, đăng nhập bằng phiên Laravel Fortify tại `/login`; `/app/login` chuyển về đó. Filament phục vụ giao diện quản trị tại `/app`.

## Yêu cầu

- PHP 8.4 với các extension chuẩn Laravel, `pdo_pgsql`, `pgsql`, `mbstring`, `intl`, `fileinfo`, `curl`, `openssl`, `zip` và XML/DOM.
- Composer 2.
- PostgreSQL 18 đang chạy; có thể dùng PostgreSQL cài riêng hoặc Laragon có PostgreSQL.
- Git. Node.js 22.12+ và npm chỉ cần nếu muốn build tài nguyên Vite mặc định.

PHP 8.4 được chọn để chạy Pest 5. Phiên bản dependencies cụ thể nằm trong `composer.lock`; dùng `composer install`, không dùng `composer update` khi thiết lập máy mới.

## Chạy trên máy cá nhân

```sh
git clone https://github.com/supernetec01/viz-ecom.git
cd viz-ecom
git switch task/t11-job-management
composer install
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

Trang chào mặc định chạy được mà chưa build Vite. Nếu phát triển CSS/JavaScript, cài dependencies và build trước khi chạy:

```sh
npm ci
npm run build
```

## Kiểm tra trước khi tạo pull request

```sh
composer validate --strict
composer test
composer lint
```

- `composer test`: Pest chạy trên PostgreSQL thật, bao gồm đăng nhập/2FA, phân quyền công ty, thao tác Filament, lời mời tài khoản và dữ liệu/máy trạng thái tin.
- `composer lint`: Pint kiểm tra định dạng, Larastan phân tích tĩnh ở mức 5. Sửa định dạng bằng `vendor/bin/pint` nếu cần.
- `phpunit.xml` buộc database test là `viz_ecom_test` và bỏ `DB_URL`, tránh vô tình dùng database phát triển. Test migration làm mới database test; không trỏ cấu hình test vào dữ liệu thật.
- GitHub Actions chạy mỗi lần push và mở/cập nhật PR: PostgreSQL 18, PHP 8.4, cài dependencies từ lockfile, migration + seed, Pest, Pint và Larastan.

Đọc [AGENTS.md](AGENTS.md) trước khi làm task. Mỗi task một nhánh và một PR; chờ xem lại trước khi chuyển task kế tiếp.
