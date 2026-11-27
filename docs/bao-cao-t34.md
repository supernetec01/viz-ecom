# T34 — Viewer PDF và tải bản gốc

## Đã làm

PDF.js6.5.438 chính thức, pin npm/lockfile, giấy phépApache2 được copy cùng asset build. Worker/CMap/standard fonts tự host, không CDN/dịch vụ ngoài. Canvas một trang, trước/sau, zoom100/150/200%, tối đa8triệu pixel; không HTML annotation/scriptPDF, không WebAssembly/dynamic eval. FontFace tắt và render glyph canvas. CSP viewer nonce/worker-self/object-none/frame-none, không unsafe-eval/unsafe-inline cho script; response bytesT33 vẫn sandbox/script-none.

ApplicationPolicy::fileStates chỉ metadata sau quyền detail. Detail có trạng thái/links; viewer không chứa contact/notes/path. Pending/error/infected không linkbytes; clean/previewpending có tải gốc, previewfailed có thông báo đã chốt. Download redirect tạo signedURL mới, không để link hết hạn sau khi mở trang lâu. Mỗi file fetch vẫn qua routeT33/policy/audit, không giả audit fileview khi chỉ mở shell. Không cache/localStorage/serviceworker; pagehide dọn canvas/worker, bfcache quay lại phải reload.

## Tiêu chí đạt / test

PrivateFileViewerTest14ca: unpaid/foreign/missing403 thống nhất, sửa fileID/thu hồi quyền trước viewer hoặc download, loginrequired; readiness5 tổ hợp không linkbytesblocked; authorized3vai trò; CSPnonce/noeval/noinline, no-store/noindex; downloadredirect đúng actor/hạn5phút; detailkhông lộpath/têngốc. ApplicationDetailsTest tiếp tục xanh. Full local943passed/3307assertions +1skip symlinkWindows; composer lint và npm build xanh. CI commit cuối ghi trongPR.

Browser trước lượt gián đoạn: PDF2 trang giả có chữ hiện rõ trong canvas, chuyển sang trang2 đúng. Viewport390x844 thấy chữ/nút, toolbar xuống hàng; không consoleerror. Clickdownload nhận file-14.docx; SHA25679d6e68eb9f765230de9e1790e3b0eb8783a97e3d33362d60d561fcc1cfb5d98 khớp DOCXgốc. Đây là viewport mô phỏng, chưa phải điện thoại thật. Khi tiếp tục ngày27/11, IAB không khả dụng và Chrome đang có người dùng thao tác nên dừng UI, không lặp các ca đã qua. Pending/failed được xác nhận HTTP. Fixtures/file/quyền/đơn/tài khoản thử đã dọn, audit giữ nguyên.

## Chưa đạt / giới hạn

Chưa điện thoại thật/iOS/Safari (T48). Không tìm kiếm/select text/annotation/editPDF/print, không thuộc tiêu chíT34. PDF cần bộ giải mãWasm có thể không render vớiuseWasmfalse; lỗi chỉ báo chung, vẫn có tải gốc khiclean. Không nới CSP hoặc dùng dịch vụ ngoài để khắc phục. PDF productionT23 vẫn tắt. T35 mới rate limit/cảnh báo.

## Giả định

Production chạy npm ci + npm run build, deploy cảbuild/pdfjs vàworker, không chỉmanifest. Node>=22.13, browser hỗ trợmoduleworker/canvas; không dùngunsafe-eval fallback. PDF/DOCX nguyên bản/preview đã qua workerT27; fixture scan trạng thái giả chỉ phục vụQAroute/UI, không thay AVtest.

## Đề xuất

T48 thử máythật và corpusfont/ảnh phong phú, cập nhậtPDF.js có kiểm traCSP; cân nhắc mởrộngcodec khi có nhu cầu và được duyệt. T35 dùng chungRecordAudit và hạn mức theoactor, không đếm từngtrang canvas như thêm1lượt tải.
