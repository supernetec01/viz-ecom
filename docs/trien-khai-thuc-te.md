# Checklist chuyển từ demo sang triển khai thực tế

Cập nhật ngày 21/11/2026, đối chiếu mã đến T15, địa chỉ làm việc và bản sửa CSP T11. Tài liệu này ghi các chỗ phải thay cấu hình, kiểm tra vận hành hoặc hoàn thành chức năng trước khi mở cho người dùng thật. **Các ô chưa đánh dấu là chưa có bằng chứng kiểm tra production**, kể cả khi test tự động đang xanh.

## Những phần đang dùng cấu hình demo/local

Trạng thái mặc định của mọi hàng dưới đây: **chưa xác minh trên production**. Chỉ xác nhận đạt khi có kết quả kiểm tra thực tế, người thực hiện và ngày kiểm tra.

| Phần | Hiện trạng và ảnh hưởng | Việc phải làm khi triển khai | Cách xác nhận đạt |
| --- | --- | --- | --- |
| Trang công khai T14/T15 | `/` và `/viec-lam` đã dùng dữ liệu/điều kiện thật; ảnh kiểm tra có tin giả lập đã được dọn. CSS build trong `public/build` không được Git theo dõi | Khi triển khai/cập nhật: `npm ci`, `npm run build`, đưa `public/build` vào gói phát hành; không cần Vite dev server. Kiểm tra tin thật đã duyệt/published, còn hạn và công ty xác minh; không seed tin giả vào production | Trang chủ/danh sách có CSS, không lỗi asset/CSP; kiểm tra 360 px, đổi trang không trùng với dữ liệu cố định; tin paused, bị từ chối hoặc vừa hết hạn không còn trong danh sách. Nếu chưa có tin hợp lệ thì hiện trạng thái trống, không phải lỗi gửi dữ liệu |
| Duyệt tin và thông báo T13 | Đã có hàng pending cho admin, duyệt/từ chối, lịch sử quyết định và thông báo database cho nhân viên phụ trách. Thông báo lưu thật, không dùng email/queue; polling 30 giây | Chạy migration T13 trước khi phục vụ mã mới; kiểm tra phân công staff và quyền admin thực tế. Không cấu hình SMTP/worker để sửa chuông thông báo. Nếu cần email kết quả duyệt, phải bổ sung và kiểm tra riêng | Tài khoản staff không mở được `/app/duyet-tin`; admin duyệt/từ chối được; staff đang phụ trách nhận chuông, mở đúng tin và thấy lý do. Thu hồi phân công thì link bị chặn; kiểm tra lỗi và thao tác lặp trên môi trường triển khai |
| Địa chỉ làm việc | Tỉnh chọn từ danh mục 34 tỉnh; xã/phường/đặc khu và địa chỉ chi tiết hiện nhập văn bản, chưa đối chiếu danh mục mã xã. Tin cũ giữ thiếu địa chỉ, không tự điền | Xác minh địa chỉ làm việc thực tế, tên đơn vị hành chính mới và danh mục tỉnh tại ngày triển khai. Bổ sung địa chỉ các tin cũ qua bản nháp. Nếu bổ sung bộ chọn xã/phường sau này, phải có nguồn chính thức, phiên bản và cách cập nhật, không nhập bộ dữ liệu chưa kiểm chứng | Thử tạo/sửa/xem trước và nhân bản tin; kiểm tra đủ số nhà/đường hoặc thôn, xã/phường/đặc khu và tỉnh. T18 phải hiển thị cùng địa chỉ đầy đủ cho ứng viên; kiểm tra trên thiết bị khác |
| Email lời mời/reset mật khẩu (T03, T09) | `MAIL_MAILER=log`: chỉ ghi nội dung email vào log, không gửi tới hộp thư. Tạo tài khoản thành công không có nghĩa người nhận đã nhận mail | Cấu hình SMTP/dịch vụ gửi thật, địa chỉ From và xác minh miền gửi theo yêu cầu nhà cung cấp. Không dùng `log`, `array` hoặc failover sang `log` làm bằng chứng gửi thành công | Người nhận nhận được email thật; link đặt mật khẩu dùng được một lần và hết hạn sau 24 giờ. Thử cả tình huống SMTP lỗi và gửi lại |
| URL trong email và link nội bộ | `APP_URL` local là localhost. Người nhận ở máy khác không truy cập được; bộ quét T12 dùng hostname này để phân biệt link nội bộ | Đặt URL HTTPS chính thức; kiểm tra URL Laravel thực sự sinh ra dưới cấu hình web server/proxy | Mở email và link từ thiết bị/mạng khác; không có localhost/127.0.0.1. Link nội bộ qua T12, link ngoài vẫn pending |
| Tài khoản và dữ liệu mẫu | Seeder local/testing tạo ba tài khoản mẫu; dữ liệu demo đã được người dùng nhập để thử. Seeder production không tạo mẫu nhưng cũng không tự xóa tài khoản đã có nếu sao chép DB demo | Dùng database production riêng, tạo admin thật với mật khẩu riêng và 2FA. Không mang tài khoản/mật khẩu/bí mật 2FA mẫu hoặc dữ liệu thử vào production | Đăng nhập được admin thật, mã khôi phục hoạt động một lần; không có tài khoản mẫu hoặc dữ liệu thử còn quyền truy cập |
| Bí mật và thư mục local | `.env`, `.tools/`, `.runtime/` là dữ liệu local bị Git bỏ qua; có thể chứa khóa, log và ghi chú đăng nhập demo | Không sao chép các thư mục/tệp này trong gói phát hành. Cấp secret production riêng, giới hạn người đọc; sao lưu APP_KEY của môi trường thật | Không tải được secret/log qua HTTP. Có quy trình giữ APP_KEY; không sinh lại tùy tiện khi đã có dữ liệu 2FA mã hóa |
| Cách chạy web server | `php artisan serve`, PostgreSQL local cổng 55432 và PHP portable phục vụ thử nghiệm trên máy hiện tại | Thiết lập dịch vụ web/database trên máy chủ; document root trỏ vào `public/`, cấu hình HTTPS, `APP_ENV=production`, `APP_DEBUG=false`, database riêng | Truy cập được tên miền thật qua HTTPS, `/up` hoạt động, trang lỗi không lộ stack trace; dịch vụ tự chạy lại sau khởi động máy |
| Cookie, HTTPS và CSP | Chạy HTTP local; CSP Filament đã có ngoại lệ nonce + eval chỉ `/app` | Kiểm tra HTTPS/proxy, cookie Secure/HttpOnly/SameSite và nonce trên môi trường triển khai. Giữ CSP công khai/đăng nhập không eval; không bật inline script để chữa lỗi giao diện | Đăng nhập/2FA và thao tác qua nhiều yêu cầu không mất phiên; kiểm tra header và console thực tế. Test CSP phải được sửa cùng mọi thay đổi policy |
| Queue | Cấu hình database queue dùng bảng `queue_jobs`; chưa có thiết lập worker chạy thường trực trên máy chủ | Khi chức năng dùng queue được đưa lên, cấu hình worker dưới trình quản lý tiến trình, theo dõi job lỗi và khởi động lại worker khi triển khai mã mới | Job thực sự được xử lý, lỗi được phát hiện và retry không tạo tác dụng phụ trùng. Lời mời T09 hiện gửi đồng bộ sau commit, nên bật worker không sửa được việc `MAIL_MAILER=log` |
| Scheduler và lưu trữ | Chưa có lịch nghiệp vụ T42 hoặc luồng xử lý CV hoàn chỉnh. Disk local mặc định trỏ vào `storage/app/private` và có `serve=true` | Khi đến task file/lịch: thiết lập lịch chạy, disk CV riêng tư và kiểm tra mọi đường truy cập đi qua policy/audit. Không coi tên thư mục private hoặc URL ký là đủ bảo vệ | Thử truy cập file trực tiếp/ký URL/đổi ID/hết quyền đều bị chặn đúng. T33 gắn `csp.private-file`; T42 chạy thật và chạy lặp an toàn |

## Kiểm tra riêng email trước khi mở tính năng mời tài khoản

- [ ] Cấu hình thực tế đã nạp đúng mailer/host/port/From; không chỉ sửa `.env` mà quên cập nhật cache cấu hình hoặc tiến trình đang chạy.
- [ ] Gửi lời mời đến hộp thư thử do người kiểm tra kiểm soát, xác nhận email đến hộp thư và kiểm tra cả thư rác; kết quả `Notification::fake()` chỉ chứng minh mã có yêu cầu gửi, không chứng minh giao hàng thật.
- [ ] Link trỏ đúng tên miền HTTPS, mở được trên thiết bị khác, đặt được mật khẩu; không gửi mật khẩu qua email.
- [ ] Gửi lại lời mời tạo link mới; link trước đó không còn dùng được. Kiểm tra dùng lại link, hết 24 giờ và tài khoản bị khóa.
- [ ] Mô phỏng SMTP lỗi: thông báo đúng trạng thái, không báo đã giao đến hộp thư; tài khoản đã tạo sau commit vẫn có thể gửi lại lời mời mà không tạo tài khoản trùng. Đây là trường hợp cần xác minh/xử lý trước mở thật, chưa coi là đã đạt chỉ dựa trên luồng thành công hiện tại.
- [ ] Email reset/lời mời và liên kết có token không bị ghi vào log production hoặc đưa ra trang công khai; kiểm tra cấu hình transport và logging thực tế.

## Chức năng chưa xây không phải là cấu hình demo

Không thể chỉ đổi `.env` để biến các phần sau thành chức năng hoàn chỉnh:

- T13 đã có duyệt/từ chối và thông báo trong hệ thống; chưa có email kết quả duyệt. Nút Gửi đăng đã nối bộ quét T12. Vẫn cần kiểm tra production theo bảng trên, không đồng nhất test local với vận hành thật.
- T14–T16 đã có trang chủ, danh sách tin published còn hạn, phân trang và bộ lọc GET, kiểm tra trình duyệt 360 px; cần build asset khi triển khai. T17–T21: tìm kiếm không dấu, chi tiết tin/công ty, SEO/sitemap/chia sẻ chưa xây. Thẻ tin chưa có nút chi tiết/ứng tuyển; không coi đây là luồng nhận hồ sơ đã hoàn tất.
- T22 trở đi: nhận hồ sơ/CV, đồng ý xử lý dữ liệu, captcha, kiểm tra file, quét virus/chuyển đổi xem trước và thông báo. Phải hoàn tất các task liên quan trước nhận hồ sơ thật.
- T29–T39: quyền xem hồ sơ, chính sách trả dữ liệu theo quyền, audit, đơn hàng/cấp quyền. Không được vận hành dữ liệu ứng viên thật khi các hàng rào này chưa đủ.
- T40–T42 và T46: gói đẩy tin, lịch hết hạn/paused và xóa PII/file sau 6 tháng, công cụ admin xóa hợp lệ. Chỉ cấu hình cron không tự tạo các chức năng chưa viết.
- T47: trang tổng quan nhà tuyển dụng. T48: rà soát cuối và hướng dẫn vận hành; phải dùng cả môi trường gần production, không chỉ máy local hoặc CI.

Thanh toán chuyển khoản do admin xác nhận thủ công và chia sẻ link Zalo không qua API là **phạm vi thật đã chốt cho giai đoạn đầu**, không phải phần demo cần tự đổi sang thanh toán tự động, API Zalo, JWT/Sanctum hoặc OTP ứng viên.

## Điều kiện xác nhận sẵn sàng triển khai

- [ ] Các task cần cho đợt phát hành đã hoàn tất và PR được xem lại/merge theo đúng thứ tự; không tự coi các nhánh đang mở là bản production.
- [ ] `composer test`, `composer lint` và CI xanh trên đúng commit phát hành; build asset cần thiết và kiểm tra trình duyệt trên bản triển khai.
- [ ] Từng hàng áp dụng ở bảng trên có bằng chứng kiểm tra môi trường thật, người xác nhận và ngày kiểm tra. Nếu chưa áp dụng phải ghi rõ lý do/tính năng nào chưa mở.
- [ ] SMTP, URL, đăng nhập/2FA, mất phiên, dữ liệu theo công ty, các thao tác thật và luồng lỗi đã được thử từ thiết bị người dùng.
- [ ] Có sao lưu database/file và thử khôi phục; có cách theo dõi lỗi, queue/scheduler nếu đã dùng; có kế hoạch quay lại bản trước mà không mất dữ liệu.
- [ ] Không chạy `migrate:fresh`, test hoặc seed dữ liệu mẫu vào database production. Chỉ chạy migration phù hợp của bản phát hành theo kế hoạch đã kiểm tra và có sao lưu.

## Mẫu cập nhật khi làm task tiếp theo

Nếu task dùng giả lập, mailer log, dữ liệu mẫu, đường dẫn local hoặc phụ thuộc dịch vụ chưa cấu hình, bổ sung một hàng gồm:

`Task/phần → đang mô phỏng gì → ảnh hưởng khi chạy thật → việc phải làm → cách kiểm tra → trạng thái → người/ngày xác nhận`.

Đóng task mã nguồn không tự đánh dấu các ô kiểm tra production. Tài liệu này chưa thay thế việc cấu hình SMTP hoặc triển khai máy chủ; yêu cầu hiện tại chỉ ghi chú và theo dõi, không đổi cấu hình đang chạy.
