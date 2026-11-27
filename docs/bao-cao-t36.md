# T36 — giới hạn hai phiên cho nhà tuyển dụng

## Đã làm

Registry DB mới (migration 000021), Laravel cookie session giữ nguyên. Đăng nhập hoàn tất MFA mới đăng ký slot, cùng transaction audit đăng nhập. Khóa user để tuần tự hóa login đồng thời. Phiên thứ ba thu hồi phiên đăng nhập cũ nhất, không dựa last_activity; middleware kiểm tra lại ở web, Filament và Livewire trước chi tiết/file. Không giới hạn admin/staff. Logout thu hồi slot; idle timeout dùng SESSION_LIFETIME; password stamp chặn cả thay đổi mật khẩu không qua model event. Lỗi DB chặn truy cập.

Cookie ghi nhớ cũ toàn tài khoản bị vô hiệu khi vượt ngưỡng; cookie vừa phát cho login mới được cập nhật đúng token sau xoay. Hai phiên còn lại vẫn hoạt động bằng session cookie. Registry không dựa riêng trên dòng sessions, nên khôi phục payload hoặc request cũ ghi lại sessions không phục hồi được slot đã thu hồi.

## Tiêu chí và test

`EmployerSessionsTest`: 24 ca/136 assertions: oldest employer session after a third independent browser; block panel/Livewire/detail/viewer/signed file handlers; generic JSON401; legacy session; foreign registry ID; idle exact boundary; login order instead of activity; admin/staff exempt; accounts independent; old remember cookie invalid/newest valid/active sessions preserved; MFA before slot; logout replay; changed password; DB failure; login rollback; own-slot replacement; competing PostgreSQL login row lock. Baseline test ban đầu đỏ (phiên cũ trả200); sau triển khai xanh. Bộ tổng local: 991 passed/3630 assertions, một ca symlink Windows bỏ qua có từ trước, 233,45 giây; qpdf/LibreOffice sandbox bật. Composer lint xanh. Kết quả CI trên đúng commit ghi trong PR.

## Chưa làm và giới hạn

Không thu hồi bytes hoặc request đã được chấp nhận trước lúc thu hồi; chặn yêu cầu kế tiếp như quy tắc đã duyệt. Không đếm thiết bị vật lý, đếm phiên cookie; người dùng có thể chia sẻ cùng cookie. Không có trang quản lý thiết bị hay tự khóa tài khoản. Registry chưa có lịch dọn (T42). Chưa kiểm tra thủ công ba trình duyệt vật lý; test dùng cookie độc lập qua HTTP Laravel thật với database session.

## Giả định

Đã duyệt cả ba quyết định ngày27/11. Các instance cùng DB; triển khai migration trước mã. Phiên employer hiện có đăng nhập lại một lần. Admin/staff vẫn theo 2FA và trạng thái tài khoản hiện có. Không thêm dependency hoặc sửa migration cũ.

## Đề xuất

T42 dọn registry đã thu hồi/hết hạn theo thời hạn được duyệt; T48 kiểm tra nhiều máy thực tế và tất cả instance dùng cùng DB. T37 triển khai tiếp trên nhánh/PR riêng sau T36 xanh.
