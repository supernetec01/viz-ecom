# Các điểm cần duyệt trước T34–T35

## T34 — PDF.js chạy trên website của mình

Đề xuất thêm npm `pdfjs-dist` chính thức Mozilla, khóa phiên bản trong lockfile, giữ giấy phép Apache2. Không thêm Composer package. Nguồn: https://github.com/mozilla/pdf.js và https://mozilla.github.io/pdf.js/api/draft/module-pdfjsLib.html . Thư viện miễn phí; vẫn cần cập nhật bản vá khi vận hành.

Trang viewer Blade riêng, chỉ sau ApplicationPolicy; dữ liệu file lấy qua route T33 có cookie/chữ ký/kiểm tra lại quyền/audit mỗi request. Asset/worker/font/CMap cần thiết được phục vụ cùng website, không CDN hoặc dịch vụ đọc CV bên ngoài. File response giữ CSP sandbox/script none. Trang viewer dùng CSP riêng có nonce, không unsafe-eval/unsafe-inline cho script; không tự nới CSP để chạy thư viện. Không chạy scripting/HTML annotations/liên kết nhúng trong PDF. Hiển thị từng trang, giới hạn kích thước canvas và mức zoom; có thông báo lỗi chung, không log nội dung/URL riêng tư.

T34 chỉ viewer, trạng thái xử lý và tải bản gốc; dùng FileReadiness/PrivateFileLinks. Pending/error/infected không có link xem/tải; clean/preview failed vẫn có nút tải và thông báo đã chốt. Link mới khi tải lại trang; không cache nội dung hoặc lưu PDF vào localStorage/service worker. Kiểm tra desktop/mobile bằng fixture giả. Chưa cài thư viện hoặc đổi CSP khi chưa được duyệt.

## T35 — Ngưỡng và phạm vi đề xuất

- Mỗi tài khoản, mọi vai trò: tối đa10 request xem/tải file cộng chung và30 request xem chi tiết trong cửa sổ60 giây. Check quyền trước; vượt ngưỡng trả429/Retry-After, không bytes. Các ngưỡng cấu hình được, không tự khóa tài khoản hay thu hồi grant.
- Cảnh báo admin trong hệ thống; gộp cùng tài khoản trong15 phút. Không email/SMS, không tên/contact/nội dung ứng viên. Cần test đồng thời để tránh vượt bộ đếm bằng nhiều request.
- RecordAudit là writer duy nhất, append-only. Đăng nhập thành công phải ghi audit; audit lỗi không hoàn tất đăng nhập. Điểm gọi cấp/thu hồi quyền phải nhận actor rõ ràng và có test; T38/T39 nối workflow thật, không suy ra người thu hồi từ created_by hoặc làm trước giao diện thanh toán.
- Không đặt thời hạn lưu/xóa audit hoặc cảnh báo ởT35. Không tự thêm thư viện hoặc workflow T36–T39.

Người dùng đã trả lời “duyệt cả3” cho các điểm trên. T34 hoàn tất/check xanh trước khi tạo nhánh T35. Các quyết định bảo mật/file trước đó giữ nguyên.
