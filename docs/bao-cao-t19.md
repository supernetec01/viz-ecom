# T19 — Trang công ty công khai

## Đã làm

GET `/cong-ty/{id}` hiển thị tên, loại, địa chỉ, giới thiệu, ngành hàng, sản phẩm và kênh bán hàng/video. Chỉ công ty đã xác minh, chưa xóa mới có trang công khai. Chỉ select dữ liệu công khai; không lấy mã số thuế, logo_path nội bộ, người phụ trách hoặc tài khoản. Văn bản thoát HTML; URL cũ kiểm tra lại theo quy tắc T06, không nhúng hoặc tải URL từ server; link mở tab mới với `noopener noreferrer`.

Danh sách 12 tin/trang của đúng công ty dùng public scope, chỉ tin đang nhận hồ sơ. Tên công ty trong view con T18 thành link ở published/paused/expired/closed. Không thay luồng xác thực hoặc CSP; trang công ty no-store. Không thêm migration/dependency.

## Tiêu chí đạt và test

`tests/Feature/PublicCompanyTest.php`:

- `does not expose missing unverified partially verified or deleted companies` (404 trước test cho phép).
- `shows only the public company projection with escaped profile text and safe external channels`.
- `omits unsafe legacy channel URLs without fetching or embedding external content`.
- `lists only the selected company open jobs with stable pagination`.
- `links every publicly viewable job state to its real company page` (4 trạng thái).
- `keeps verified companies accessible when they have no open jobs`.

Điều chỉnh test liên kết T18 vì T19 đã tạo route thật. `composer test`: **439 test, 1510 assertions**; `composer lint` (Pint/Larastan) và `npm run build` xanh ngày 21/11/2026. Chỉ kiểm tra trình duyệt luồng mới: bấm tên công ty từ tin closed mở đúng trang; thông tin, 13 tin/2 trang và thuộc tính link ngoài đúng. 360 px hết tràn ngang kể cả tên rất dài sau sửa xuống dòng; console không lỗi. Ảnh local `.runtime/t19-company-desktop.jpg`, `.runtime/t19-company-mobile.jpg`; log `.runtime/t19-console.json`. Dùng dữ liệu giả, không thay tài khoản; giữ fixture để kiểm tra T20 rồi dọn.

## Chưa đạt

Không còn tiêu chí T19 chưa đạt trên local; chưa kiểm tra môi trường production. Giới thiệu không phải rich text; logo chưa có luồng public upload được duyệt nên không xuất đường dẫn lưu nội bộ.

## Giả định và đề xuất

URL công ty dùng ID ổn định, không thêm slug/migration ngoài phạm vi. Công ty đã xác minh vẫn có trang khi chưa có tin đang tuyển. Đề xuất kiểm tra lại tên miền/HTTPS thật khi triển khai theo checklist vận hành. Theo yêu cầu làm T19–T20 liên tiếp, tiếp tục T20 trên nhánh/PR riêng, không làm T21.
