<?php

use App\Services\ContentScanner;

dataset('contact variants', [
    'mobile plain' => ['0901234567', 'phone'],
    'dots' => ['090.123.4567', 'phone'],
    'digit pairs' => ['09.01.23.45.67', 'phone'],
    'spaces' => ['09 0 1 2 3 4 5 6 7', 'phone'],
    'dashes' => ['090-123-4567', 'phone'],
    'slashes' => ['090/123/4567', 'phone'],
    'parentheses' => ['(090) 123 4567', 'phone'],
    'plus 84' => ['+84 901 234 567', 'phone'],
    'country code' => ['84901234567', 'phone'],
    '00 country code' => ['0084 901 234 567', 'phone'],
    'trunk code' => ['+84 (0) 901 234 567', 'phone'],
    'split country code' => ['+8 4 9 0 1 2 3 4 5 6 7', 'phone'],
    'letters' => ['0a9b0c1d2e3f4g5h6i7', 'phone'],
    'spaced letters' => ['0 a 9 b 0 c 1 d 2 e 3 f 4 g 5 h 6 i 7', 'phone'],
    'zero width' => ["090\u{200B}123\u{200B}4567", 'phone'],
    'full width digits' => ['０９０１２３４５６７', 'phone'],
    'spoken digits' => ['không chín không một hai ba bốn năm sáu bảy', 'phone'],
    'spoken unaccented' => ['khong chin khong mot hai ba bon nam sau bay', 'phone'],
    'mixed spoken and numeric' => ['không9 012 345 67', 'phone'],
    'spoken alternative four' => ['không chín không một hai ba tư năm sáu bảy', 'phone'],
    'landline' => ['024 1234 5678', 'phone'],
    'international landline' => ['+84 24 1234 5678', 'phone'],
    'email' => ['hr@example.com', 'email'],
    'email uppercase' => ['HR@EXAMPLE.COM', 'email'],
    'email spaces' => ['hr @ example . com', 'email'],
    'email brackets' => ['hr [at] example [dot] com', 'email'],
    'email parentheses' => ['hr(at)example(dot)com', 'email'],
    'email words' => ['hr a còng example chấm com', 'email'],
    'email full width' => ['ｈｒ＠ｅｘａｍｐｌｅ．ｃｏｍ', 'email'],
    'email html entities' => ['hr&#64;example&#46;com', 'email'],
    'zalo' => ['Liên hệ ZALO', 'zalo'],
    'zalo spaced' => ['z a l o', 'zalo'],
    'zalo punctuation' => ['z.a-l_o', 'zalo'],
    'zalo zero' => ['zal0', 'zalo'],
    'zalo split markup' => ['za<strong>lo</strong>', 'zalo'],
    'inbox' => ['INBOX để biết thêm', 'inbox'],
    'inbox spaces' => ['in box', 'inbox'],
    'inbox punctuation' => ['i.n-b_o.x', 'inbox'],
]);

it('flags contact variants without exposing matched phone or email in reasons', function (string $html, string $rule) {
    $result = (new ContentScanner('https://jobs.example.test'))->scan(['description' => $html]);
    expect($result->requiresReview())->toBeTrue()->and(array_column($result->findings, 'rule'))->toContain($rule);
    foreach ($result->findings as $finding) {
        if ($finding['rule'] !== 'external_link') {
            expect(array_keys($finding))->toBe(['field', 'rule']);
        }
    }
})->with('contact variants');

dataset('external links', [
    'https' => ['https://external.com/apply', 'https://external.com/apply'],
    'http' => ['http://external.com', 'http://external.com'],
    'bare domain' => ['example.com', 'example.com'],
    'www path' => ['www.example.com/abc', 'www.example.com/abc'],
    'short link' => ['bit.ly/apply', 'bit.ly/apply'],
    'zalo invitation' => ['zalo.me/123456', 'zalo.me/123456'],
    'whatsapp' => ['wa.me/123456', 'wa.me/123456'],
    'telegram' => ['t.me/recruiter', 't.me/recruiter'],
    'messenger' => ['m.me/recruiter', 'm.me/recruiter'],
    'mixed case' => ['HtTpS://ExAmPlE.CoM/Apply', 'HtTpS://ExAmPlE.CoM/Apply'],
    'unicode lookalike' => ['https://jobs.exаmple.test/apply', 'https://jobs.exаmple.test/apply'],
    'bare unicode lookalike' => ['exаmple.com', 'exаmple.com'],
    'hidden href' => ['<a href="https://evil.test/apply">https://jobs.example.test</a>', 'https://evil.test/apply'],
    'protocol relative' => ['<a href="//evil.test/apply">Nộp hồ sơ</a>', '//evil.test/apply'],
    'userinfo trap' => ['https://jobs.example.test@evil.test/apply', 'https://jobs.example.test@evil.test/apply'],
    'suffix trap' => ['https://jobs.example.test.evil.test/apply', 'https://jobs.example.test.evil.test/apply'],
    'external embedded image' => ['<img src="https://images.evil.test/ad.png">', 'https://images.evil.test/ad.png'],
    'backslash userinfo trap' => ['<a href="https://evil.test\@jobs.example.test">Ứng tuyển</a>', 'https://evil.test\@jobs.example.test'],
    'backslash relative trap' => ['<a href="/\evil.test/apply">Ứng tuyển</a>', '/\evil.test/apply'],
]);

it('records the offending external link for review', function (string $html, string $link) {
    $result = (new ContentScanner('https://jobs.example.test'))->scan(['description' => $html]);
    expect($result->findings)->toContain(['field' => 'description', 'rule' => 'external_link', 'value' => $link]);
})->with('external links');

dataset('clean content', [
    'no links' => ['Tuyển nhân viên kinh doanh, ứng tuyển ngay trên website.'],
    'salary' => ['Lương 15.000.000 VND'],
    'salary range' => ['Thu nhập 15.000.000–20.000.000 đồng/tháng'],
    'distance' => ['Địa chỉ cách trung tâm 12.5 km'],
    'hours colon' => ['Làm việc 08:00–17:00, lương 15.000.000'],
    'hours h' => ['Ca 08h00–17h00, lương 15.000.000'],
    'counts' => ['Tuyển 12 người, kinh nghiệm 2 năm, hợp đồng 12 tháng.'],
    'internal https' => ['https://jobs.example.test/viec-lam'],
    'internal case' => ['HTTPS://JOBS.EXAMPLE.TEST/viec-lam'],
    'internal bare domain' => ['jobs.example.test/viec-lam'],
    'internal anchor' => ['<a href="https://jobs.example.test/viec-lam">Ứng tuyển</a>'],
    'internal relative' => ['<a href="/viec-lam/123">Ứng tuyển</a>'],
    'internal protocol relative' => ['<a href="//jobs.example.test/viec-lam">Ứng tuyển</a>'],
    'fragment' => ['<a href="#ung-tuyen">Ứng tuyển</a>'],
    'relative path with dot' => ['<a href="/viec-lam/category.test">/viec-lam/category.test</a>'],
]);

it('allows clean descriptions including salaries distances and internal links', function (string $html) {
    $result = (new ContentScanner('https://jobs.example.test'))->scan(['description' => $html]);
    expect($result->findings)->toBe([]);
})->with('clean content');

it('identifies only the outside link when internal and external links are mixed', function () {
    $result = (new ContentScanner('https://jobs.example.test'))->scan(['benefits' => '<a href="https://jobs.example.test">Nội bộ</a> <a href="https://outside.test/apply">Khác</a>']);
    expect($result->findings)->toBe([['field' => 'benefits', 'rule' => 'external_link', 'value' => 'https://outside.test/apply']]);
});

it('scans only job content not separate company channels', function () {
    $result = (new ContentScanner('https://jobs.example.test'))->scan(['title' => 'Nhân viên kinh doanh', 'company_channels' => 'https://shopee.vn/shop', 'company_introduction' => 'https://company.example']);
    expect($result->requiresReview())->toBeFalse();
});
