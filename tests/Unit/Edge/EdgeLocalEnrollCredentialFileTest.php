<?php

namespace Tests\Unit\Edge;

use App\Console\Commands\EdgeLocalEnrollCommand;
use PHPUnit\Framework\TestCase;

/**
 * CRLF HARDENING (owner-approved, 7 Nov 2026) — `edge:local:enroll --credential-file` reads the credential the
 * installer wrote to a temp file. 0.8.2 trimmed only "\n", so a CRLF-terminated file (Windows editors, PowerShell
 * `Set-Content`) enrolled a credential ending in "\r" that no keyboard can type: the LAB2C5D clone-login refusal of
 * 31 Oct 2026. Only the line terminator may be normalised; every meaningful character is kept.
 */
class EdgeLocalEnrollCredentialFileTest extends TestCase
{
    public function test_crlf_and_lf_terminated_files_yield_the_same_credential(): void
    {
        $this->assertSame('CashierPass1', EdgeLocalEnrollCommand::credentialFromFileContents("CashierPass1\r\n"));
        $this->assertSame('CashierPass1', EdgeLocalEnrollCommand::credentialFromFileContents("CashierPass1\n"));
        $this->assertSame('CashierPass1', EdgeLocalEnrollCommand::credentialFromFileContents("CashierPass1\r"));
        $this->assertSame('CashierPass1', EdgeLocalEnrollCommand::credentialFromFileContents('CashierPass1'));
        // several trailing terminators (an editor that adds a blank line) are still only terminators
        $this->assertSame('CashierPass1', EdgeLocalEnrollCommand::credentialFromFileContents("CashierPass1\r\n\r\n"));
    }

    public function test_meaningful_characters_are_never_changed(): void
    {
        // spaces and tabs at either end are part of the credential as chosen
        $this->assertSame('  spaced out  ', EdgeLocalEnrollCommand::credentialFromFileContents("  spaced out  \r\n"));
        $this->assertSame("\ttabbed", EdgeLocalEnrollCommand::credentialFromFileContents("\ttabbed\n"));
        // an inner newline is not a terminator
        $this->assertSame("two\r\nlines", EdgeLocalEnrollCommand::credentialFromFileContents("two\r\nlines\r\n"));
        // non-ASCII and symbols pass through byte-for-byte
        $this->assertSame('pässw0rd!#%&/()=?', EdgeLocalEnrollCommand::credentialFromFileContents("pässw0rd!#%&/()=?\r\n"));
        // an empty file stays empty (the command then falls back to the secret prompt)
        $this->assertSame('', EdgeLocalEnrollCommand::credentialFromFileContents("\r\n"));
        $this->assertSame('', EdgeLocalEnrollCommand::credentialFromFileContents(''));
    }

    public function test_a_credential_file_round_trips_through_the_installer_path(): void
    {
        // the installer writes the file; the command reads it with file_get_contents — prove the whole path
        $file = tempnam(sys_get_temp_dir(), 'edge-cred-');
        try {
            file_put_contents($file, "Round Trip 42\r\n");
            $this->assertSame('Round Trip 42', EdgeLocalEnrollCommand::credentialFromFileContents((string) file_get_contents($file)));
        } finally {
            @unlink($file);
        }
    }
}
