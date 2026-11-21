# T14 — Khung giao diện công khai

## Đã làm

Trang chủ `/` tiếng Việt thay trang chào Laravel, dùng Blade layout chung cho header, footer và nội dung công khai. Có liên kết chuyển đến nội dung, điều hướng tới phần việc làm/hướng dẫn và đường đăng nhập khu vực làm việc; không thêm tài khoản ứng viên hoặc chức năng của task sau. Tại T14 phần việc làm chỉ có trạng thái chờ cập nhật, không dùng tin hoặc số liệu giả.

CSS được build bằng Vite/Tailwind đã có, font hệ thống, không tải font từ bên ngoài; điều hướng không cần JavaScript. CI thêm build bằng `npm ci` và `npm run build` trước test PHP, không thêm dependency. README cập nhật yêu cầu build cho máy mới và triển khai.

## Tiêu chí đạt và test

- `renders the Vietnamese public home with navigation footer and no candidate account requirement`: trang tiếng Việt, viewport, header/footer, skip link, link đăng nhập; không còn nội dung Laravel mặc định.
- `keeps public navigation usable without scripts or external fonts`: các đích điều hướng có thật trong trang, không script/font ngoài, không có unsafe-eval trong CSP công khai.
- `composer test`: **337 passed, 1141 assertions**. `composer lint` (Pint/Larastan) và `npm run build`: xanh.
- Trình duyệt ở 360 × 800: `scrollWidth = clientWidth = 360`, header/main/footer không tràn ngang; chữ nền 16 px, link điều hướng tối thiểu 44 px và nút chính ít nhất 48 px. Nút Khám phá việc làm đi đúng anchor. Console không lỗi. Đã xem bố cục desktop; không kiểm tra lại luồng quản trị/đăng nhập không đổi.
- Ảnh local bị Git bỏ qua: `.runtime/t14-desktop.jpg`, `.runtime/t14-mobile.jpg`, `.runtime/t14-mobile-full.jpg`. Đã bỏ viewport thử sau kiểm tra.

## Chưa đạt, giả định và đề xuất

Không còn tiêu chí T14 chưa đạt trong môi trường local. Chưa kiểm tra trên điện thoại thật/production. Chưa có danh sách thật ở T14 (thuộc T15), bộ lọc, tìm kiếm, chi tiết hoặc ứng tuyển (task sau). Giả định giữ thương hiệu Viz Ecom và dùng nội dung tiếng Việt theo dự án hiện tại. Đề xuất dùng cùng layout cho T15 và tiếp tục kiểm tra những phần mới, không lặp lại kiểm tra quản trị đã đạt.

Người dùng đã yêu cầu thực hiện liên tiếp T14 và T15, nên sau nhánh/PR riêng của T14 tiếp tục T15; không làm trước T16.
