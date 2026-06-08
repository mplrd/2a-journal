<?php

namespace Tests\Unit\Tools;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the pure helpers of tools/support-cli/support-cli.php.
 * The CLI file defines its functions in the global namespace and guards its
 * entry point on argv[0] === __FILE__, so require'ing it here is side-effect free.
 */
class SupportCliTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../../../../tools/support-cli/support-cli.php';
    }

    public function testParseEnvFileStripsQuotesAndComments(): void
    {
        $env = parseEnvFile("# comment\nJOURNAL_API_URL=http://x/api\nA=\"quoted\"\nB='single'\n\nNOEQ\n");
        $this->assertSame('http://x/api', $env['JOURNAL_API_URL']);
        $this->assertSame('quoted', $env['A']);
        $this->assertSame('single', $env['B']);
        $this->assertArrayNotHasKey('NOEQ', $env);
    }

    public function testParseStatusLineReadsCodeAndHandlesRedirects(): void
    {
        $this->assertSame(200, parseStatusLine(['HTTP/1.1 200 OK', 'Content-Type: application/json']));
        $this->assertSame(401, parseStatusLine(['HTTP/2 401 Unauthorized']));
        // On a redirect chain, the last status line wins.
        $this->assertSame(200, parseStatusLine(['HTTP/1.1 301 Moved', 'HTTP/1.1 200 OK']));
        $this->assertSame(0, parseStatusLine(['not-a-status-line']));
    }

    public function testParseArgsSplitsPositionalOptionsAndFlags(): void
    {
        [$positional, $options, $flags] = parseArgs(['42', '--body=hello world', '--confirm']);
        $this->assertSame(['42'], $positional);
        $this->assertSame('hello world', $options['body']);
        $this->assertTrue($flags['confirm']);
    }

    public function testBuildListQueryMapsAndDropsEmpty(): void
    {
        $this->assertSame('', buildListQuery(['status' => '']));
        $q = buildListQuery(['status' => 'OPEN,IN_PROGRESS', 'per-page' => '50', 'ignored' => 'x']);
        $this->assertStringContainsString('status=' . urlencode('OPEN,IN_PROGRESS'), $q);
        $this->assertStringContainsString('per_page=50', $q);
        $this->assertStringNotContainsString('ignored', $q);
    }

    public function testFormatTicketRowIncludesKeyFields(): void
    {
        $row = formatTicketRow([
            'id' => 7, 'type' => 'BUG', 'status' => 'OPEN', 'priority' => 'HIGH',
            'user_email' => 'a@b.co', 'subject' => 'Crash on save',
        ]);
        $this->assertStringContainsString('#7', $row);
        $this->assertStringContainsString('BUG', $row);
        $this->assertStringContainsString('Crash on save', $row);
    }

    public function testFormatTicketDetailRendersThreadAndDetails(): void
    {
        $text = formatTicketDetail([
            'id' => 3, 'subject' => 'Need X', 'type' => 'FEATURE', 'status' => 'OPEN',
            'priority' => 'NORMAL', 'user_email' => 'u@x.co',
            'details' => ['benefit' => 'saves time'],
            'messages' => [
                ['author_is_admin' => false, 'created_at' => '2026-06-05', 'body' => "line1\nline2"],
                ['author_is_admin' => true, 'created_at' => '2026-06-05', 'body' => 'ok', 'attachments' => [1]],
            ],
        ]);
        $this->assertStringContainsString('Ticket #3', $text);
        $this->assertStringContainsString('benefit: saves time', $text);
        $this->assertStringContainsString('[USER ]', $text);
        $this->assertStringContainsString('[ADMIN]', $text);
        $this->assertStringContainsString('line2', $text);
        $this->assertStringContainsString('1 attachment(s)', $text);
    }
}
