# Bổ sung địa chỉ làm việc theo từng tin — 21/11/2026

## Đã làm

- Tách Tỉnh/Thành phố, Xã/Phường/Đặc khu và Số nhà, đường / thôn, tổ dân phố trên form tạo/sửa. Không thêm quận/huyện. Đổi tỉnh xóa hai ô còn lại để tránh giữ địa chỉ của tỉnh cũ.
- Migration mới thêm `ward_name` và `work_address`, giữ dữ liệu cũ; PostgreSQL chỉ cho phép cùng null (tin cũ) hoặc cả hai có nội dung.
- Bắt buộc đủ ba phần với tin mới, khi sửa hoặc gửi đăng bản nháp. Chuẩn hóa khoảng trắng đầu/cuối, giới hạn độ dài, không nhận HTML. Kiểm tra tại form và model/service.
- Xem trước ghép địa chỉ chi tiết → xã/phường/đặc khu → tỉnh; không tự dùng địa chỉ trụ sở. Nhân bản giữ địa chỉ. Tin paused không sửa được hai trường mới.
- Đưa hai trường qua bộ quét T12; thay đổi địa chỉ xóa kết quả quét cũ như các nội dung khác.
- Tin cũ thiếu địa chỉ hiển thị thông báo; vẫn cho phép thao tác trạng thái ngoài bản nháp. Nhân bản tin thiếu địa chỉ báo cần bổ sung, không tạo tin thiếu âm thầm.

## Tiêu chí và test

Các test mới nằm trong `tests/Feature/JobWorkAddressTest.php`:

| Tiêu chí | Tên test |
| --- | --- |
| Bắt buộc địa chỉ | `requires ward and detailed workplace address for new jobs`; `requires both address fields in the create and edit forms` |
| Kiểm tra nội dung/độ dài | `rejects incomplete oversized or HTML workplace addresses at the model boundary` |
| Tỉnh hợp lệ | `rejects a child location supplied as the province` |
| Đổi tỉnh | `clears local address fields when the selected province changes` |
| Lưu và xem đầy đủ | `saves trims and previews the full worksite separately from the company office` |
| Nhân bản | `copies the complete work address when duplicating a job` |
| Chặn sửa paused | `rejects work address changes while paused` |
| Dữ liệu cũ | `retains legacy jobs without inventing an address and requires completion before draft submission`; `keeps legacy published jobs pausable without modifying their missing address`; `warns when duplicating a legacy job with no work address` |
| Ràng buộc DB | `enforces complete address pairs in PostgreSQL while permitting legacy null pairs` |
| Quét nội dung | `screens contact details placed in either work address field` |

## Giả định, giới hạn và đề xuất

Kết quả kiểm tra ngày 21/11/2026: `composer test` xanh **309 test, 980 assertions**; `composer lint` xanh (Pint và Larastan). Trình duyệt local: tạo tin với Khánh Hòa + xã/phường nhập tay + địa chỉ chi tiết, lưu và xem trước đủ địa chỉ; mở sửa, đổi sang Hà Nội làm trống hai ô, lưu thiếu bị chặn, nhập lại rồi lưu/xem trước địa chỉ mới thành công. Console không có lỗi trong phiên kiểm tra. Dữ liệu và tài khoản thử riêng được dọn sau kiểm tra, không thay đổi tài khoản admin của người dùng.

Ảnh kiểm tra local được lưu ở `.runtime/work-address-form.jpg` và `.runtime/work-address-preview.jpg` (bị Git bỏ qua); dữ liệu trong ảnh là giả lập, không phải địa chỉ công ty thật. Migration đã được áp dụng trên DB demo bằng `php artisan migrate`, không xóa hoặc điền đoán địa chỉ tin cũ.

Một tin có một địa chỉ làm việc chính. Xã/phường/đặc khu nhập tên mới bằng văn bản; chưa xác minh tên hoặc quan hệ xã-tỉnh bằng mã hành chính, chưa có bản đồ/geocoding. Không tự đoán địa chỉ của tin cũ hoặc vị trí trụ sở công ty. Bộ chọn tỉnh hiện theo dữ liệu đã có trong dự án.

Nguồn đối chiếu mô hình địa chỉ hai cấp: [Quyết định 19/2025/QĐ-TTg](https://vanban.chinhphu.vn/?classid=1&docid=214409&pageid=27160&typegroupid=5), [danh mục cơ quan thống kê](https://danhmuchanhchinh.nso.gov.vn/Default.aspx). Chưa nhập hàng loạt dữ liệu xã từ bản PDF ký số hoặc nguồn bên thứ ba chưa kiểm chứng. Trước chạy thật cần đối chiếu tên hiện hành; không mặc định bản danh mục 2025 luôn cập nhật đến ngày triển khai.

Chưa đạt ngoài phạm vi đợt này: trang công khai T18 để ứng viên xem địa chỉ. Đã ghi yêu cầu dùng địa chỉ đầy đủ vào thiết kế và checklist triển khai, chưa làm trước task. Đề xuất sau: danh mục xã/phường phụ thuộc tỉnh có nguồn/phiên bản và quy trình cập nhật, nếu cần hạn chế lỗi nhập tay.
