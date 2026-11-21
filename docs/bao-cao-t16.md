# T16 — Bộ lọc việc làm GET

## Đã làm

Form GET tại `/viec-lam` có tỉnh/thành (`dia_diem`), nhóm vị trí (`vi_tri`), lương khởi điểm từ (`luong_tu`), kinh nghiệm tối đa (`kinh_nghiem`), loại công ty (`loai_cong_ty`). Các điều kiện kết hợp AND, giữ nguyên điều kiện công khai của T15. Phân trang chỉ mang tham số đã kiểm tra; submit form không mang page để bắt đầu lại trang 1. Xóa bộ lọc trở về danh sách chung. Giá trị đã chọn được phục hồi từ URL, không lưu riêng bằng JavaScript/session.

Giả định nghiệp vụ được ghi ngay trên giao diện: lương lọc theo salary_min đã công bố, đạt ngưỡng 5/10/15/20/30 triệu VND; loại lương thỏa thuận/không công bố khi chọn ngưỡng. Kinh nghiệm lọc yêu cầu <= 0/1/2/3/5/10 năm; 0 là không yêu cầu kinh nghiệm. Tỉnh chỉ nhận cấp tỉnh, vị trí là danh mục nhóm vị trí hiện có. Tham số được kiểm tra trước truy vấn; tham số đã biết nhưng sai trả trang tiếng Việt HTTP 422, không redirect lặp. Tham số không hỗ trợ không mở rộng dữ liệu.

## Tiêu chí đạt và tên test

`tests/Feature/PublicJobFiltersTest.php`:

- `filters public jobs by the advertised starting salary`.
- `filters each supported field and restores its selected value from the URL` (5 bộ lọc, mở URL hai lần).
- `combines all filters with AND and keeps excluding nonpublic jobs`.
- `supports zero experience and excludes negotiable or unknown salaries only when filtering salary`.
- `preserves filters across pagination and resets the page on form submission`.
- `rejects malformed known filters without redirect loops or reflecting unsafe input` (6 trường hợp).

Kết quả local: `composer test` **374 passed, 1285 assertions**; `composer lint` và `npm run build` xanh. Đã có test đỏ cho lương trước khi triển khai.

Trình duyệt 360 × 800: kết hợp lương từ 10 triệu + tối đa 1 năm + công ty chủ việc trả 5 tin thử; reload URL phục hồi các select. Chỉ chọn loại công ty trả 15 tin, sang trang 2 còn 3; bấm Back phục hồi đúng 12 ID trang đầu và loại công ty. Không cuộn ngang (scrollWidth bằng clientWidth), console không lỗi. Không kiểm tra lại quản trị/đăng nhập không đổi. Ảnh local `.runtime/t16-filters-mobile.jpg` bị Git bỏ qua. Đã dọn công ty/15 tin fixture, không đổi tài khoản.

## Chưa đạt, giả định và đề xuất

Không còn tiêu chí T16 chưa đạt trong local; chưa kiểm tra điện thoại thật/production. Các ngưỡng và cách hiểu lương/kinh nghiệm là lựa chọn triển khai đã nêu trên, không suy diễn mức lương tối đa là khởi điểm. Không thêm migration/dependency. Đề xuất bổ sung ngưỡng khác nếu có nhu cầu; chưa tự thêm lọc cấp xã hoặc hình thức làm việc. Người dùng yêu cầu T16 và T17 liên tiếp nên tiếp tục T17 trên nhánh/PR riêng, chưa làm T18.
