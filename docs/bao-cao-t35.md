# T35 — Audit truy cập, đăng nhập và cảnh báo

## Đã làm

Migration mới 000020 cho phép audit toàn hệ thống không gán công ty, thêm khóa chống lặp sự kiện cấp/thu hồi và hai bảng trạng thái hạn mức/cảnh báo. Không sửa migration cũ. `RecordAudit` vẫn là nơi duy nhất ghi audit, chỉ thêm dòng; không có model hay endpoint sửa/xóa nhật ký.

Đăng nhập hoàn tất MFA mới ghi `auth.login`. Audit và `last_login_at` cùng transaction. Khi audit lỗi, đăng xuất thiết bị hiện tại và trả 503 chung, không để lại phiên hay cookie ghi nhớ hợp lệ. Hook chạy lại file T27 cũng ghi audit trong transaction hiện có, chỉ mã file và bước xử lý.

`AccessLimits` áp dụng mọi vai trò, theo tài khoản: 10 lượt xem/tải file cộng chung và 30 lượt chi tiết trong cửa sổ 60 giây tính từ request đầu. Khóa dòng PostgreSQL tuần tự hóa cả hai nhóm. Kiểm tra policy trước, sau đó tính lượt trước I/O; request đã được nhận vẫn tính nếu I/O lỗi. Request bị policy từ chối không tính. Vượt ngưỡng ghi audit, trả 429 kèm Retry-After, không trả nội dung hồ sơ.

Cảnh báo và thông báo database được ghi đồng bộ cùng transaction, chỉ gửi admin đang hoạt động, gộp cùng tài khoản trong 15 phút cho cả hai nhóm. Trang `/app/canh-bao-truy-cap` chỉ cho admin hiện tại, chỉ hiện mã tài khoản/vai trò/công ty, loại truy cập, giới hạn và giờ Việt Nam. Không có dữ liệu ứng viên hoặc thao tác CRUD. Không email, SMS, tự khóa tài khoản hay thu hồi quyền.

Hook `RecordAudit::grantChange` yêu cầu actor rõ ràng, mã quyền và operation ULID ổn định. Chỉ admin active, kiểm tra trạng thái quyền, khóa bản ghi và chống ghi trùng operation. T38/T39 phải gọi hook trong transaction thay đổi quyền; T35 chưa tạo workflow hay giao diện thanh toán.

## Tiêu chí đạt và test

`AccessAuditLimitsTest` kiểm tra: 403 ngoài admin; hạn mức dùng chung cho ba vai trò; audit đúng một dòng cho mỗi truy cập hoặc lần chặn; biên 60 giây và Retry-After; hạn mức riêng từng tài khoản; gộp cảnh báo 15 phút và đúng người nhận; không có PII; lỗi DB/audit từ chối nội dung; quyền admin đọc lại từ DB; hai kết nối tranh tạo cùng trạng thái bị khóa với mã 55P03.

Các ca đăng nhập kiểm tra audit một lần, không ghi email/mật khẩu, lỗi audit xóa phiên/cookie ghi nhớ, và chưa xong MFA thì chưa ghi audit. Các ca hook kiểm tra actor thực sự, operation không lặp, từ chối nonadmin/locked, rollback thay đổi của caller khi audit lỗi và từ chối tái dùng operation cho actor khác. Không có route sửa/xóa audit.

Nhóm liên quan: 132 test, 460 assertions xanh; lint xanh. Kết quả bộ tổng và CI của commit cuối ghi trong PR. Không điều khiển Chrome khi người dùng đang thao tác; IAB hiện không khả dụng. Trang cảnh báo được kiểm tra qua HTTP/Filament render.

## Chưa đạt và giới hạn

Workflow cấp/thu hồi thật chờ T38/T39 theo quyết định đã duyệt. Chưa đặt thời hạn lưu hoặc tự xóa audit/cảnh báo/trạng thái; việc đó thuộc T42. Cửa sổ bắt đầu từ request đầu, không phải sliding window, nên có thể có hai lô truy cập sát biên. Cảnh báo dựa trên tần suất, chưa phân tích hành vi hay số ứng viên khác nhau. Không thu hồi được bytes đã tải. PDF production T23 giữ tắt.

## Giả định

Mọi app instance dùng chung PostgreSQL. Chạy migration trước khi deploy mã đăng nhập mới. DB, audit hoặc thông báo lỗi thì chặn truy cập, chấp nhận giảm tính sẵn sàng để không bỏ qua kiểm tra. Audit toàn hệ thống dùng company_id null, không gán công ty tùy ý. Rollback migration 000020 sẽ dừng nếu đã có các dòng này để bảo toàn nhật ký.

## Đề xuất

T38/T39 giữ operation ULID khi retry và rollback toàn bộ thay đổi nếu audit lỗi. T42/T48 chốt retention, dung lượng DB và cấu hình proxy chỉ tin IP/CIDR thật. Điều chỉnh ngưỡng theo tải thực tế khi triển khai. T36 xử lý giới hạn phiên riêng, chưa làm trước.
