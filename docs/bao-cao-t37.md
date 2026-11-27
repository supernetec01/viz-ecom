# T37 — yêu cầu mở hồ sơ và hướng dẫn chuyển khoản

## Đã làm

Menu `/app/mo-ho-so` trong Filament, header từ danh sách hồ sơ, form POST native có CSRF theo từng tin. Chỉ employer active thuộc công ty, tin published/paused/expired/closed chưa xóa, gồm tin chưa có hồ sơ. Trang chỉ chọn cột tin/công ty, không đọc dữ liệu ứng viên. Server không nhận company/amount/type/status từ trình duyệt. Pending đúng company/job, dùng lại giữa các tài khoản công ty, không tạo khi grant còn hiệu lực. Khóa dòng và partial unique index chống tranh tạo.

Migration000022 thêm job_id nullable cho legacy, FK kép job/company, reference duy nhất và JSONB snapshot ngân hàng. Chỉ backfill tin qua grant đã có, không đoán pending legacy; gặp pending chưa xác định thì chặn tạo đơn để admin đối chiếu. Không sửa migration cũ. Snapshot giá/ngân hàng/reference/phạm vi bất biến qua save/quiet save. Rollback dừng khi có instructions để bảo toàn dữ liệu tài chính.

Giá VND nguyên dương và ba trường ngân hàng bắt buộc, không default demo. Thiếu/sai cấu hình trả503 tiếng Việt và không ghi đơn. Đơn đã tạo giữ nguyên giá/hướng dẫn khi đổi cấu hình. Trang hướng dẫn chỉ cho employer đúng công ty, kiểm tra lại role/membership/job; no-store/noindex, escape HTML, không liên hệ/file/portfolio ứng viên. Không có payment API, email, QR, xác nhận paid hoặc cấp quyền ở T37.

## Tiêu chí và test

`AccessOrdersTest`: 403 admin/staff/foreign/missing/draft/pending/deleted/mất membership; actor stale; từng trạng thái được mua và không cần hồ sơ; bỏ qua body giả mạo; chống trùng giữa retries/tài khoản công ty; grant effective chặn, expired/revoked/future không chặn; thiếu/sai giá/ngân hàng; receipt cross-company; HTML/Livewire không PII và projection đúng; pending legacy; unique DB và hai kết nối tranh insert; model bất biến; FK scope; backfill/rollback giữ dữ liệu; từng tin riêng; đọc lại quyền receipt; escape bank text; không cấp quyền sang tin khác; effective query chặn order scope bị đổi; link T31.

Đã tái hiện race: dữ liệu grant ở projection trước khóa có thể cũ; test `rechecks a grant acquired after the projected job result instead of trusting that stale result` đỏ trước sửa, xanh sau khi truy vấn grant lại sau khóa. T38 phải tuân thủ khóa job trước order. T35 audit hook chưa nối workflow paid vì workflow chưa thuộc task này.

58 ca T37/241 assertions và lint xanh; nhóm liên quan trước lượt cuối 121 test/483 assertions xanh. Bộ tổng local trước hai ca ID overflow cuối: 1049 passed/3880 assertions, một ca symlink Windows bỏ qua có từ trước, 238,03 giây, sandbox PDF/file bật; lint xanh. Sau đó bổ sung hai ca BIGINT overflow đỏ trước sửa (500 PostgreSQL), chặn ID ngoài giới hạn trước query để trả403. Bộ T37 cuối: 60 ca/245 assertions xanh, lint và npm build xanh. Migration000022 đã áp dụng demo local, không xóa dữ liệu. CI trên commit cuối ghi trong PR. Test từ chối403 được viết/chạy đỏ trước mã T37. Hai test migration cũ cập nhật thứ tự rollback/up cho migration con mới; không sửa file migration cũ.

## Chưa làm và giới hạn

Chưa xác nhận tiền thực, chưa có workflow admin/thời hạn quyền (T38), không tự đánh paid hay cấp quyền. Cấu hình local để trống, không tự chọn giá/ngân hàng; bấm khi chưa cấu hình nhận thông báo503 như đã duyệt. Không UI chỉnh giá/ngân hàng, không xuất Excel/email/QR. Kiểm tra UI bằng HTTP/Livewire render và form action/CSRF; chưa QA thủ công trên điện thoại thật. Chưa kiểm chứng cấu hình ngân hàng/nhận tiền production.

## Giả định

Đã duyệt ba quyết định T36–T37 ngày27/11. VND nguyên dương mỗi tin, admin chọn thời hạn ở T38. Công ty chưa xác minh lại vẫn được theo phạm vi đã duyệt; không thêm điều kiện xác minh mới vào mua hồ sơ cũ. App instances cùng PostgreSQL. Order legacy chỉ được xác định tin khi có grant, không sửa dữ liệu chưa rõ.

## Đề xuất

T38 paid+grant+audit cùng transaction và cùng thứ tự khóa; đối chiếu chuyển khoản thật trước mở vận hành. Nếu đã đổi ngân hàng, admin xử lý đơn chờ cũ có snapshot cũ, không tự sửa số tiền/thông tin nhận. T43 chốt chặn thanh toán khi thêm cờ vi phạm. T48 kiểm chứng nhiều instance và nhiều thiết bị, cùng checklist demo/production.
