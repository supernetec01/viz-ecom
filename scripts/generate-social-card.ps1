# Optional Windows maintainer tool; the generated PNG is committed and requires no runtime dependency.
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Drawing
$bitmap = [System.Drawing.Bitmap]::new(1200, 630)
$graphics = [System.Drawing.Graphics]::FromImage($bitmap)
$graphics.SmoothingMode = 'AntiAlias'
$graphics.TextRenderingHint = 'AntiAliasGridFit'
$ink = [System.Drawing.SolidBrush]::new([System.Drawing.ColorTranslator]::FromHtml('#163e36'))
$muted = [System.Drawing.SolidBrush]::new([System.Drawing.ColorTranslator]::FromHtml('#53675c'))
$accent = [System.Drawing.SolidBrush]::new([System.Drawing.ColorTranslator]::FromHtml('#dce7c8'))
$brand = [System.Drawing.Font]::new('Segoe UI', 34, [System.Drawing.FontStyle]::Bold)
$heading = [System.Drawing.Font]::new('Segoe UI', 48, [System.Drawing.FontStyle]::Bold)
$body = [System.Drawing.Font]::new('Segoe UI', 23)
try {
    $graphics.Clear([System.Drawing.ColorTranslator]::FromHtml('#f3f5ec'))
    $graphics.FillEllipse($accent, 930, -160, 500, 500)
    $graphics.DrawString('viz ecom', $brand, $ink, 72, 54)
    $graphics.DrawString('Tìm công việc.', $heading, $ink, 68, 194)
    $graphics.DrawString('Chọn hướng đi.', $heading, $ink, 68, 280)
    $graphics.DrawString('Việc làm thương mại điện tử', $body, $muted, 74, 426)
    $graphics.DrawString('Thông tin rõ ràng. Cơ hội phù hợp.', $body, $muted, 74, 480)
    $target = Join-Path $PSScriptRoot '../public/images/social-card.png'
    [System.IO.Directory]::CreateDirectory([System.IO.Path]::GetDirectoryName($target)) | Out-Null
    $bitmap.Save($target, [System.Drawing.Imaging.ImageFormat]::Png)
} finally {
    $body.Dispose(); $heading.Dispose(); $brand.Dispose()
    $accent.Dispose(); $muted.Dispose(); $ink.Dispose(); $graphics.Dispose(); $bitmap.Dispose()
}
