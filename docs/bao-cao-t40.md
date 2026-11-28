# Báo cáo T40 — gói đẩy tin và đối chiếu đơn

## Đã làm

Một task, nhánh codex/t40-boost-plans từ T39, PR draft riêng. Người dùng đã duyệt cả ba quy tắc trong docs/de-xuat-t40.md. Migration000025 mới tạo catalog/boost và snapshot orders, không sửa migration cũ hoặc đoán dữ liệu legacy. Không thêm dependency, seed giá hoặc tài khoản demo.

Bốn màn hình Filament: Gói đẩy tin, Đẩy tin, Đơn đẩy tin, Gói đẩy đã gán; chọn tin/đối chiếu bằng Blade có CSRF/no-store/noindex. Admin quản lý/ngừng bán catalog, staff chỉ xem/chọn tin công ty mình; employer403. Đơn pending snapshot server, một pending/tin. Chỉ admin đối chiếu: tiền/ref chuẩn hóa, unique ngân hàng toàn hệ thống; paid+boost+hai audit atomic/idempotent. Gift/trial0đ có lý do vẫn qua admin. Thời gian đúng N×24h từ server confirm, không vượt hạn tin, nửa mở. Cho nhiều boost chồng, paused chạy và duplicate không sao chép.

## Tiêu chí đạt và tên test

JobBoostManagementTest:

- Denial-first: `denies boost management and plan catalog pages to employers`, `denies plan creation to staff`, `denies creating an order for another company job before inspecting the selected plan`, `denies payment confirmation to staff and employers before reading a foreign order`. Baseline 7 đỏ trước route/service, sau mã đều403 xanh.
- `lets an admin create and stop selling plans while staff can only view`: create/edit Filament, staff chỉ catalog read, edit403.
- `creates a scoped pending order with a server snapshot and ignores forged financial fields`: admin/staff, client giả amount/company/days/priority/paid không có tác dụng.
- `confirms once with exact 24 hour duration and two safe audit events in one transaction`: đúng thời điểm server, ngày VN, hai audit, replay không thêm boost/audit.
- `keeps old order and boost snapshots when the plan changes or stops selling`: sửa/ngừng bán catalog không đổi cũ, chặn đổi snapshot/khoảng/status đã cấp.
- `reuses one pending order per job without replacing its plan snapshot`; `refuses a disabled plan for a new order and missing bank configuration without partial data`.
- `denies foreign order reads and rechecks staff assignment at submit and list query time`; `rechecks admin privileges at confirmation and hides confirmation controls from staff`; `scopes all three Filament lists to the assigned company and exposes no candidate fields`.
- `rejects nonreceiving job states at order creation`: draft/pending/expired/closed. `checks restriction and deletion again at payment time`: company/job xóa mềm và seam vi phạm.
- `rejects insufficient remaining job time both at request and delayed confirmation without cutting days`; `accepts a boost ending exactly at job expiry and uses half open boundaries without a scheduler`: trước starts, tại starts, trước ends, tại ends và thiếu một giây.
- `allows overlapping boosts and pausing resuming and duplicating never extend or copy them`; `allows assigning a boost to a paused job with the clock running`.
- `requires a reason for gift and trial and confirms zero amounts without a bank reference`.
- `keeps pending on mismatched invalid or zero received amounts`; `rejects missing bank references and arbitrary starts or ends`; `rejects a normalized duplicate transaction reference across boost orders`; `does not allow paying an access order with the same normalized bank transaction used for a boost`.
- `rolls back paid boost and the first audit if the second audit fails`: trigger PostgreSQL thực chỉ gây lỗi ở job_boost.created sau order.paid_confirmed, không mock bỏ qua writer, rollback cả hai audit/paid/boost.
- `rejects invalid plan values in both model validation and database checks`; `enforces boost dates scope one order per boost and used plan retention in PostgreSQL`.
- `refuses paid orders missing a boost without silently creating one`; `preserves old jobs through the new migration round trip and refuses dropping plan history`.
- `does not change public ordering before T41 and keeps financial pages no store and noindex`: T40 không làm trước T41, audit không có bank/holder/received payload.

BoostOrderConcurrencyTest + BoostOrderWorker (CLI-only testing/viz_ecom_test): ba trường hợp bằng hai tiến trình thật, dùng pg_stat_activity xác nhận chờ khóa. Tạo request đồng thời chỉ một pending; hai admin confirm cùng đơn chỉ một boost/hai audit; hai đơn cùng bank/ref chỉ một paid, đơn sau pending. Fixture commit chỉ trên test DB và dọn theo ID công ty thử.

## Kết quả kiểm tra

Composer test đầy đủ trên Windows với PDF_SANDBOX_TESTS=1 và FILE_PROCESSING_TESTS=1: **1232 passed, 1 skipped, 4644 assertions**, 268.69 giây. Một test symlink Windows thiếu quyền, CI Ubuntu kiểm tra đầy đủ. T40 thêm 61 trường hợp (58 chức năng/dữ liệu và 3 process concurrency). Bộ trước test migration cuối: 57 passed/189 assertions; concurrency 3 passed/23 assertions.

Composer lint (Pint/Larastan) xanh; npm run build xanh; git diff --check sạch. Migration000025 đã áp dụng vào DB demo local bằng migrate và config:clear. Không tạo catalog giá giả, đơn/boost hoặc giao dịch thật, không đổi tài khoản/mật khẩu/2FA demo. Dữ liệu thử chỉ trong viz_ecom_test và fixture tổng hợp; không lặp kiểm tra trình duyệt các tính năng không liên quan. CI Ubuntu của đúng commit được cập nhật trong PR trước báo hoàn tất.

## Chưa làm và giới hạn

T41 mới dùng MAX(priority) hiệu lực và nhãn Nổi bật/thứ tự công khai; không SUM hoặc bù ngày. Chưa có employer tự mua, API thanh toán, email, hoàn tiền/hủy/thay đơn; T38B cần quyết phạm vi boost khi làm. Không tự kéo hạn tin hoặc chuyển đơn pending thiếu đủ hạn. T43 flags thật vẫn chờ, dùng ApplicationRestriction seam. PDF production giữ nguyên tắt theo T23.

Luồng được kiểm bằng HTTP/Livewire và hai process PostgreSQL, chưa thay thế kiểm thử điện thoại thật ở T48. Các test tiền/đơn là dữ liệu tổng hợp, không xác minh tiền thật. Không đọc/đưa CV cá nhân vào test/Git. Không thay mật khẩu/2FA hoặc giao dịch thật trên demo.

## Giả định dùng

Giới hạn kỹ thuật catalog: days1–366, priority1–1.000.000, giá VND nguyên dương; không tự chọn giá thương mại. Gói miễn phí thể hiện bằng gift/trial0đ có reason, staff chỉ đề nghị pending, admin duyệt cuối. Không cung cấp xóa catalog, dùng ngừng bán. Invoice/boost cũ giữ snapshot khi catalog ngừng bán. Mỗi loại đơn pending độc lập (access và boost), không dùng chung một mã chuyển khoản paid.

## Đề xuất

Người dùng review T40 trước T41. Khi đưa thật, admin nhập giá/ngân hàng thật và đối chiếu chuyển khoản thủ công; không dùng dữ liệu fixture. T41 chỉ nối query/thứ tự và nhãn từ scope JobBoost::effective, có test hết hạn/paused/resume/phân trang/MAX cho nhiều boost.
