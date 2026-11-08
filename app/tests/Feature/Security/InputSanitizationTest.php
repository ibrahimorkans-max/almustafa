<?php

declare(strict_types=1);

use App\Domain\Auth\AuthContextBinder;
use App\Filament\Resources\Masters\PartyResource\Pages\CreateParty;
use App\Models\Masters\Party;
use App\Support\Security\InputGuard;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\Support\Make;

describe('the guard', function () {
    it('removes invisible characters but keeps new lines, tabs and Urdu joiners', function () {
        expect(InputGuard::clean("Ali\u{0000} Traders\u{202E}\u{FEFF}"))->toBe('Ali Traders')
            ->and(InputGuard::clean("line one\nline two\tend"))->toBe("line one\nline two\tend")
            ->and(InputGuard::clean("می\u{200C}خواهم"))->toBe("می\u{200C}خواهم")
            ->and(InputGuard::clean("bad \xC3\x28 utf8"))->toBe('bad ?( utf8');
    });

    it('finds HTML and script code, however it is written', function (string $attack) {
        expect(InputGuard::threat($attack))->not->toBeNull();
    })->with([
        '<script>alert(1)</script>',
        '<img src=x onerror=alert(1)>',
        '<svg onload=alert(1)',
        '<SCRIPT SRC=//evil.example/x.js>',
        '"><iframe src="//evil.example">',
        'javascript:alert(document.cookie)',
        'java script:alert(1)',
        'data:text/html;base64,PHNjcmlwdD4=',
        '&lt;script&gt;alert(1)&lt;/script&gt;',
        '&#60;script&#62;alert(1)',
        '&#x3c;img src=x onerror=alert(1)>',
        '<!-- hidden -->',
        '<?php system($_GET[1]); ?>',
        '<a href="//evil.example">click</a>',
    ]);

    it('lets ordinary text through: Urdu, maths signs, e-mail names, rupees', function (string $text) {
        expect(InputGuard::threat($text))->toBeNull();
    })->with([
        'Rana Rice Traders (Pvt) Ltd.',
        'المصطفیٰ رائس ملز، لودھراں',
        'moisture < 14% and broken > 5%',
        'Ali <ali@example.com>',
        'Paddy 1121 — Rs 3,450/maund, 5 > 3',
        "O'Brien & Sons; 50% advance",
        'SELECT is just a word here',
    ]);

    it('takes the code out of values that are not form fields', function () {
        expect(InputGuard::strip('Ali<script>alert(1)</script> Traders'))->toBe('Ali Traders')
            ->and(InputGuard::strip('<b>bold</b> text'))->toBe('bold text')
            ->and(InputGuard::strip('see javascript:alert(1)'))->toBe('see alert(1)')
            ->and(InputGuard::threat(InputGuard::strip('<img src=x onerror=alert(1)>Hi')))->toBeNull()
            ->and(InputGuard::strip('moisture < 14%'))->toBe('moisture < 14%');
    });

    it('makes download file names safe', function () {
        expect(InputGuard::filename('../../etc/passwd'))->toBe('passwd')
            ->and(InputGuard::filename("bill\"; filename=evil.html\r\n.pdf"))->not->toContain('"')->not->toContain("\n")->not->toContain(';')
            ->and(InputGuard::filename('رسید 12.pdf'))->toBe('رسید 12.pdf')
            ->and(InputGuard::filename('///'))->toBe('file');
    });
});

describe('every request', function () {
    beforeEach(function () {
        Route::post('/_test/echo', fn (Request $r) => response()->json($r->all()));
    });

    it('cleans values sent from anywhere, and leaves passwords and tokens exactly as sent', function () {
        $this->postJson('/_test/echo', [
            'search' => "Ali<script>alert(1)</script>\u{0000}",
            'note' => ['deep' => ['x' => '<img src=x onerror=alert(1)>ok']],
            'password' => '<P@ss>\u{0000}word',
            'api_token' => '<abc>',
            'weight' => 12500,
        ])->assertOk()->assertExactJson([
            'search' => 'Ali',
            'note' => ['deep' => ['x' => 'ok']],
            'password' => '<P@ss>\u{0000}word',
            'api_token' => '<abc>',
            'weight' => 12500,
        ]);
    });

    it('leaves form fields to their form (to say what is wrong) and never touches the signed snapshot', function () {
        $snapshot = json_encode(['data' => ['name' => '<b>x</b>'], 'checksum' => 'abc']);
        $this->postJson('/_test/echo', ['components' => [[
            'snapshot' => $snapshot,
            'updates' => ['data.name' => "<b>Ali</b>\u{0000}", 'plate.wb1' => '<script>x</script>LES-1'],
            'calls' => [],
        ]]])->assertOk()
            ->assertJsonPath('components.0.snapshot', $snapshot)
            ->assertJsonPath('components.0.updates', ['data.name' => '<b>Ali</b>', 'plate.wb1' => 'LES-1']);
    });
});

describe('every form', function () {
    beforeEach(function () {
        $this->entity = Make::entity('SEC');
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $admin = Make::account($this->entity, 'SUPER_ADMIN');
        $this->actingAs($admin, 'web')->withSession(Make::webSession($admin));
        app(AuthContextBinder::class)->bind($admin);
    });

    it('refuses HTML or script code in any text box, with a message, and saves nothing', function () {
        Livewire::test(CreateParty::class)
            ->fillForm(['name' => '<script>alert(1)</script>Rana', 'address' => '<img src=x onerror=alert(1)>', 'city' => 'javascript:alert(1)'])
            ->call('create')
            ->assertHasFormErrors(['name', 'address', 'city']);

        expect(Make::system(fn () => Party::query()->where('name', 'like', '%script%')->exists()))->toBeFalse();
    });

    it('checks phone numbers, e-mail addresses and CNICs by their format', function () {
        Livewire::test(CreateParty::class)
            ->fillForm(['name' => 'Rana Traders', 'phone' => 'call me', 'email' => 'not-an-email', 'cnic' => '12345'])
            ->call('create')
            ->assertHasFormErrors(['phone', 'email', 'cnic']);
    });
});
