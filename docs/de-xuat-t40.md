# T40 — quyết định đã duyệt ngày 28/11/2026

Người dùng duyệt cả ba câu hỏi trong phiên làm việc. Nhánh riêng codex/t40-boost-plans nối từ T39, PR riêng draft. T41 chưa triển khai trong task này.

## Luồng đơn và phân quyền

Admin hoặc staff đang hoạt động, phụ trách đúng công ty, chọn tin/gói tạo đơn pending. Chỉ admin active đối chiếu và xác nhận. Paid + job_boost + hai audit cùng transaction; bấm lại paid có boost trả bản cũ, không cấp lại; paid thiếu boost dừng và báo lỗi. Đơn đúng company/job/type job_boost; mỗi đơn một boost. Bank transfer cần mã giao dịch chuẩn hóa bỏ khoảng trắng/uppercase, dùng cùng partial unique ngân hàng/tài khoản/mã với T38, kể cả khác loại đơn. Tiền thực nhận phải bằng đơn, lệch giữ pending và audit amount_mismatch; không sửa tiền hoặc tự hủy/tạo lại.

Gift/free_trial tạo đơn 0 đồng có lý do bắt buộc; vẫn chỉ admin xác nhận. Staff có thể đề nghị qua đơn pending nhưng không tự cho hưởng gói. Chưa có thanh toán tự động, email, employer tự mua, hoàn tiền hay hủy đơn trong T40.

Một pending job_boost theo company/job; sử dụng lại đơn đang chờ kể cả người khác cùng phạm vi yêu cầu, không thay gói/giá của đơn cũ. Pending access_grant vẫn độc lập, không ảnh hưởng lẫn nhau. Khi tin không còn đủ thời hạn lúc xác nhận thì giữ pending, không tự chuyển đơn; hủy/thay thế để T38B xem xét phạm vi bổ sung.

## Thời gian

Chỉ published hoặc paused, published_at đã đến, tin và công ty chưa xóa mềm, chưa bị ApplicationRestriction chặn. Starts do server lấy khi xác nhận, ends=starts+N×24h trong UTC; hiển thị VN. Không nhập tay ngày, không backdate, không lên lịch T40. [starts,ends) dùng chung trong JobBoost::effective; không cần lịch đêm để biết hết hạn.

Tin phải còn đủ thời gian đến ends của boost, bằng expires_at được nhận, thiếu dù một giây bị từ chối. Kiểm lại lúc tạo và xác nhận (khóa job); không cắt ngày hoặc kéo dài hạn tin. Paused vẫn chạy đồng hồ, resume không sửa boost; duplicate không sao chép boost. Không thay thứ tự hiển thị công khai hoặc thêm nhãn trước T41.

## Gói và snapshot

Chỉ admin tạo/sửa/ngừng bán plan; staff xem/chọn, employer bị chặn. Không cung cấp xóa gói, dùng is_active để ngừng bán; FK giữ gói đã có đơn/boost. Không seed giá demo hoặc tự đặt giá thật. Giới hạn kỹ thuật: số ngày 1–366, priority 1–1.000.000, giá VND nguyên dương; gift/trial miễn phí qua đơn, không sửa giá catalog thành 0.

Snapshot tên/ID gói, số ngày, priority, amount và ngân hàng trong order lúc yêu cầu. is_active/giá/ngày/priority thay đổi không sửa hoặc chặn đơn cũ hợp lệ. Boost lấy priority/days từ snapshot, không đọc giá catalog mới. Khoảng boost và binding bất biến sau cấp; không có route sửa/xóa boost hoặc đổi đơn paid đã gắn boost.

Cho nhiều boost chồng nhau theo quyết định người dùng. T41 sẽ dùng MAX(priority) còn hiệu lực, không SUM và không kéo dài boost cũ. T40 cung cấp effective scope + dữ liệu, chưa đổi truy vấn danh sách công khai.

## Lưu trữ và giao diện

Migration mới000025 tạo plans/job_boosts, snapshot order và unique pending boost. FK kép job/company và FK ba order/company/job; check ngày/priority/range; unique order_id. Đơn legacy thiếu snapshot không backfill/đoán gói và không được xác nhận qua luồng mới. Down dừng khi đã có plan/đơn boost/lịch sử.

Filament: Gói đẩy tin (/app/goi-day-tin), Đẩy tin (/app/day-tin), Đơn đẩy tin (/app/don-day-tin), Gói đẩy đã gán (/app/goi-da-gan). Chọn tin và đối chiếu dùng Blade với CSRF, no-store/noindex và role/scope server fresh; không lấy hồ sơ ứng viên. RecordAudit là writer duy nhất. Khóa user → job → order → boost. Không thêm dependency hoặc sửa migration cũ.
