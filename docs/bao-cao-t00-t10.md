# Báo cáo T00–T10

Đã thực hiện liên tiếp đến T10 theo yêu cầu. Mỗi task một nhánh, một PR nối tiếp task trước; chưa merge vào main. Dừng sau T10 để người dùng xem lại. Các PR được đọc theo thứ tự dưới đây.

## Đã làm và tiêu chí đạt

| Task | Kết quả | Bằng chứng kiểm thử | PR |
| --- | --- | --- | --- |
| T00 | Laravel, PostgreSQL, Pest, Pint, Larastan, README và GitHub Actions | `FoundationTest`: trang chào, health, migration, lưu/đọc PostgreSQL; Composer validate, test và lint | [#1](https://github.com/supernetec01/viz-ecom/pull/1) |
| T01 | UTC/giờ Việt Nam, tiếng Việt, HTTPS production, header bảo mật và giới hạn tốc độ | `BaseConfigurationTest`: header, HTTPS, chuyển ngày UTC sang Việt Nam, giới hạn tốc độ | [#2](https://github.com/supernetec01/viz-ecom/pull/2) |
| T02 | Vai trò/trạng thái user, ràng buộc PostgreSQL và tài khoản mẫu local/testing | `UserRolesTest`: enum và CHECK, seed idempotent, không tạo tài khoản mẫu production | [#3](https://github.com/supernetec01/viz-ecom/pull/3) |
| T03 ⚠ | Fortify đăng nhập cookie/session, mật khẩu băm, reset 24 giờ một lần, 2FA bắt buộc admin/staff, thu hồi phiên | `AuthenticationTest`: giới hạn đăng nhập, OTP/replay/recovery, xác nhận 2FA, khóa/đổi mật khẩu trong lúc chờ OTP, reset dùng lại bị từ chối | [#4](https://github.com/supernetec01/viz-ecom/pull/4) |
| T04 ⚠ | Middleware vai trò, policy mặc định từ chối, lỗi tiếng Việt | `RoleAuthorizationTest`: ma trận 403 trước test cho phép; khách chuyển đăng nhập | [#5](https://github.com/supernetec01/viz-ecom/pull/5) |
| T05 | Địa phương và nhóm vị trí, seeder chạy nhiều lần | `ReferenceDataTest`: 34 tỉnh/thành, 8 nhóm, không trùng, khóa duy nhất | [#6](https://github.com/supernetec01/viz-ecom/pull/6) |
| T06 | Công ty, kênh bán hàng, loại direct/agency và kiểm tra URL | `CompanyDataTest`: tên miền nền tảng, URL giả mạo, HTTP/HTTPS website riêng, giao thức nguy hiểm, loại công ty ở model/DB | [#7](https://github.com/supernetec01/viz-ecom/pull/7) |
| T07 ⚠ | Phân công công ty, phạm vi truy vấn và CompanyPolicy | `CompanyAuthorizationTest`: đổi ID công ty khác bị từ chối, scope từng vai trò và thu hồi phân công | [#8](https://github.com/supernetec01/viz-ecom/pull/8) |
| T08 | Filament thêm/sửa/tìm/lọc công ty, quản lý kênh; admin xác minh | `CompanyManagementTest`: thao tác theo vai trò, giả mạo phân công/xác minh bị chặn, tìm/lọc và URL; đã kiểm tra giao diện bằng trình duyệt | [#9](https://github.com/supernetec01/viz-ecom/pull/9) |
| T09 ⚠ | Tạo tài khoản công ty, mời/gửi lại liên kết, khóa/mở khóa | `EmployerAccountsTest`: 9 test, mail giả lập không chứa mật khẩu, đúng công ty, từ chối 403, hủy link cũ, thu hồi phiên và thao tác Filament | [#10](https://github.com/supernetec01/viz-ecom/pull/10) |
| T10 | Model/migration tin, validation dùng cho form, ràng buộc DB, slug và máy trạng thái | `JobDataTest`: 52 test, gồm ràng buộc lương/count/type/reference, công ty xác minh, 20 cặp trạng thái khác nhau, bản ghi cũ, slug, UTC, hạn tin và hàng đợi | PR nhánh `task/t10-job-data` |

Kết quả cuối tại T10: `composer test` **140 passed, 466 assertions**; `composer lint` **Pint và Larastan xanh**; `composer validate --strict` xanh. Test dùng database `viz_ecom_test`, tách khỏi database phát triển. Migration mới đã áp dụng lên database phát triển và seed lại không tạo trùng. Không sửa migration cũ.

## Tiêu chí chưa đạt

Không có tiêu chí còn thiếu trong phạm vi triển khai T00–T10. T10 kiểm tra lớp validation dành cho form; giao diện quản lý tin thuộc T11 và chưa được làm. Quyền xem hồ sơ, upload CV, thanh toán, trang công khai và xóa dữ liệu thuộc các task sau, chưa có dữ liệu ứng viên để xử lý.

## Giả định đã dùng

- T00: PHP 8.4 để chạy Pest 5; phiên bản dependency cố định trong `composer.lock`. PostgreSQL 18 được dùng ở local và CI.
- T01–T02: tài khoản mẫu chỉ local/testing; mật khẩu lấy từ `SEED_SAMPLE_PASSWORD` khi tạo lần đầu hoặc sinh ngẫu nhiên. Không in mật khẩu ra log.
- T03: dùng Authenticator/TOTP. Fortify miễn phí theo MIT và đã được người dùng duyệt; không dùng JWT, access/refresh token hay Sanctum. Token reset mật khẩu là liên kết một lần, không phải token đăng nhập API. Bí mật 2FA/mã khôi phục mã hóa trong DB; chỉ lưu token reset dưới dạng băm.
- T04–T05: policy chưa có hành vi nghiệp vụ sẽ mặc định từ chối; địa phương seed ở cấp tỉnh/thành, không tự nhập thêm cấp nhỏ hơn.
- T06: website riêng nhận mọi HTTP/HTTPS hợp lệ theo quyết định người dùng; kênh nền tảng vẫn kiểm tra tên miền.
- T07–T08: nhân viên quản lý công ty được phân công, admin quản lý toàn bộ; tài khoản employer không quản lý công ty. Chỉ admin xác minh.
- T09: tạo tài khoản bằng email chưa tồn tại, không tự đổi vai trò hoặc gắn lại tài khoản có sẵn. Từ chối thay đổi tài khoản dùng chung nếu nhân viên không quản lý đủ công ty liên quan.
- T10: lương là số nguyên VND, cho phép khoảng lương mở; kinh nghiệm là số năm nguyên không âm, số lượng tuyển lớn hơn 0. Khi quay lại draft, xóa thời gian đăng/hết hạn cũ để đăng lại.
- Máy trạng thái T10 theo luồng đã được duyệt: draft → pending → published → expired/closed; pending/expired/closed → draft theo bảng ở mục 3.3. Không tự thêm giao diện hoặc quyền duyệt của task sau.

## Đề xuất thêm

- Khi triển khai thật: cấu hình SMTP, tên miền và HTTPS; local chỉ dùng email thử, không dùng dữ liệu ứng viên thật với mailer ghi log.
- Xem và merge PR theo thứ tự T00 → T10, điều chỉnh base của PR kế tiếp khi cần. Hiện các nhánh đang xếp nối tiếp để giữ diff từng task riêng biệt.
- Chỉ bắt đầu T11 sau khi người dùng xem lại đợt này.

## Chạy và kiểm tra nhanh trên máy hiện tại

Trong PowerShell, tại `D:\AI\Viz-Ecom` (nhánh cuối là `task/t10-job-data`):

```powershell
$env:Path = "$PWD\.tools\php;$env:Path"
php artisan migrate --seed
php artisan serve --host=127.0.0.1 --port=8000
```

PostgreSQL local của phiên làm việc dùng cổng 55432, được cấu hình trong `.env` và `.env.testing`. Nếu máy vừa khởi động lại, bật cluster trước (cửa sổ PowerShell tại thư mục dự án):

```powershell
& 'C:\Program Files\PostgreSQL\18\bin\pg_ctl.exe' -D "$PWD\.runtime\postgres" -l "$PWD\.runtime\postgres.log" start
```

Mở <http://127.0.0.1:8000/login>. Admin/nhân viên cần hoàn thành 2FA. Nếu quên mật khẩu tài khoản mẫu, dùng chức năng quên mật khẩu; local mailer ghi liên kết vào `storage/logs/laravel.log`. Chỉ dùng địa chỉ và dữ liệu thử.

Nếu server đang chạy sẵn cổng 8000 thì chỉ mở URL, không chạy thêm server. Trong cửa sổ PowerShell khác để kiểm tra:

```powershell
$env:Path = "$PWD\.tools\php;$env:Path"
php .tools/composer.phar test
php .tools/composer.phar lint
```

Trong giao diện, kiểm tra công ty tại `/app/cong-ty` và bảng tài khoản nhà tuyển dụng trong trang sửa công ty. Chưa có form tin T11; kiểm tra T10 bằng `JobDataTest` trong bộ test.
