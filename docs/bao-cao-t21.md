# T21 — Chia sẻ và ghi nguồn

## Đã làm

- Tin còn nhận hồ sơ có ô link readonly và nút sao chép; link từ APP_URL, slug/ID thật và nguồn cố định `chia-se`, không sao chép tham số/nguồn của người xem. Dán vào Zalo hoặc kênh khác do người dùng thực hiện; không dùng API/gửi tin tự động. Không JS vẫn có ô link; lỗi clipboard hiển thị hướng dẫn chọn/copy thủ công.
- Ghi `?nguon=` vào phiên Laravel trên bốn trang GET công khai có kết quả 200/301; nguồn chỉ 1–64 ký tự ASCII an toàn, chữ thường. Không nhận URL/HTML/NUL/khoảng trắng/xuống dòng/array/chuỗi dài. TrimStrings bỏ qua riêng nguon để kiểm tra đúng giá trị gốc.
- Lấy nguồn hợp lệ gần nhất trong phiên; thiếu/sai giữ nguồn cũ. Không lưu từ auth/quản trị/404/422/sitemap/robots. Nguồn không đổi nội dung/metadata, không đưa vào canonical; redirect slug vẫn giữ nguồn qua phiên.
- Có `ApplicationSource::current()` và CODEMAP cho T24. Không thêm form/POST/hồ sơ/file, migration hoặc thư viện. Không sửa CSP.

## Tiêu chí đạt và test

`tests/Feature/ApplicationSourceTest.php`:

- `ignores unsafe malformed or oversized source values without changing page content` (10 mẫu).
- `remembers the last valid public source and retains it across visits without a new source`.
- `does not record sources from private authentication sitemap or hidden job responses`.
- `retains the source through a canonical slug redirect without putting it into canonical metadata`.
- `keeps public metadata and content identical for different valid sources`.
- `offers a fixed share URL on receiving jobs with a no-script fallback and unchanged strict CSP`.
- `does not offer sharing on nonreceiving jobs` (paused/expired/closed).

Ngày 21/11/2026: **468 test, 1670 assertions**, `composer lint` (Pint/Larastan), `npm run build` và `git diff --check` xanh. Trình duyệt: bấm nút thấy “Đã sao chép liên kết”; dùng Ctrl+V vào ô tìm kiếm local xác nhận đúng URL tin và `nguon=chia-se`, không có nguồn gốc `zalo-demo`. 360 px không tràn ngang, console không lỗi. Ảnh `.runtime/t21-share-desktop.jpg`, `.runtime/t21-share-mobile.jpg`. Dữ liệu thử một công ty/một tin đã dọn, không đổi tài khoản. Không kiểm tra lại quản trị/2FA không bị ảnh hưởng.

## Tiêu chí chưa đạt

Không còn tiêu chí T21 chưa đạt trên local. Preview Zalo từ URL HTTPS thật của T20 vẫn chờ triển khai, không coi copy thành công là đã xác minh crawler Zalo. Gắn nguồn vào hồ sơ chờ T24, không tạo endpoint tạm.

## Giả định đã dùng

Nguồn gần nhất trong phiên thay nguồn cũ, không cộng gộp lịch sử hoặc lưu cookie riêng; hết phiên thì mất nguồn. Nhãn dùng mã không dấu, ví dụ `zalo-nhom_1`, không nhập dữ liệu cá nhân. Chỉ cung cấp nút share cho tin còn nhận để giữ closed tối thiểu và không mời chia sẻ tin ngừng nhận. Nguồn là thống kê tự khai báo, không dùng phân quyền.

## Đề xuất thêm

T24 đọc nguồn qua hàm chung khi lưu, không tin nguồn POST. Khi triển khai phải cấu hình APP_URL HTTPS và kiểm tra clipboard trên thiết bị thật theo checklist. Theo yêu cầu làm hai task liên tiếp, tiếp tục T22 trên nhánh/PR riêng, không làm T23.
