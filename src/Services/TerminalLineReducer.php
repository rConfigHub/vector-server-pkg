<?php

namespace Rconfig\VectorServer\Services;

/**
 * Reduces a raw terminal output stream to the text as it finally read on
 * screen, for the `vector:ssh --log` transcript.
 *
 * Instead of merely deleting control bytes (which leaves mistyped characters
 * behind when the operator backspaces over them), this applies simple line
 * discipline: printable bytes are written at the cursor, backspace (0x08) moves
 * the cursor left, carriage return (0x0D) returns it to column 0, and a line is
 * committed on newline (0x0A) — so `verx\bsion` becomes `version`, exactly what
 * was visible when Enter was pressed. ANSI/CSI/OSC escape sequences are skipped;
 * other control bytes are ignored.
 *
 * It is stateful and fed incrementally: {@see push()} returns completed lines as
 * they finish (so the transcript is written durably line-by-line), and
 * {@see flush()} returns any unterminated final line. A sequence split across
 * two chunks is held in {@see $pending} and resolved on the next push.
 *
 * This is line-oriented, not a full terminal emulator: full-screen programs
 * (vim, pagers) that rely on absolute cursor positioning will not reconstruct
 * faithfully — interactive command entry, the point of the transcript, does.
 */
class TerminalLineReducer
{
    /** @var list<string> characters of the line currently being edited */
    private array $line = [];

    /** cursor position within {@see $line} */
    private int $cursor = 0;

    /** bytes of an escape sequence that was cut off at a chunk boundary */
    private string $pending = '';

    /**
     * Feed a chunk of raw output; returns any newly-completed lines (each with
     * its trailing "\n"), or '' if the chunk only extended the current line.
     */
    public function push(string $chunk): string
    {
        $data = $this->pending . $chunk;
        $this->pending = '';
        $out = '';
        $len = strlen($data);

        for ($i = 0; $i < $len; $i++) {
            $c = $data[$i];
            $o = ord($c);

            if ($o === 0x1B) { // ESC — start of an escape sequence
                $seqLen = $this->escapeLength($data, $i);
                if ($seqLen === -1) {       // incomplete at end of chunk
                    $this->pending = substr($data, $i);
                    break;
                }
                $i += $seqLen - 1;          // -1: the loop's $i++ consumes the last byte
                continue;
            }

            if ($c === "\n") {
                $out .= implode('', $this->line) . "\n";
                $this->line = [];
                $this->cursor = 0;
            } elseif ($c === "\r") {
                $this->cursor = 0;
            } elseif ($o === 0x08) {        // backspace
                if ($this->cursor > 0) {
                    $this->cursor--;
                }
            } elseif ($o === 0x09 || $o >= 0x20) { // tab or printable (incl. UTF-8 bytes)
                $this->put($c);
            }
            // any other control byte (BEL, etc.) is ignored
        }

        return $out;
    }

    /** Return the unterminated final line (if any) and reset state. */
    public function flush(): string
    {
        $rest = implode('', $this->line);
        $this->line = [];
        $this->cursor = 0;
        $this->pending = '';

        return $rest;
    }

    private function put(string $ch): void
    {
        if ($this->cursor < count($this->line)) {
            $this->line[$this->cursor] = $ch;
        } else {
            $this->line[] = $ch;
        }
        $this->cursor++;
    }

    /**
     * Length of the escape sequence starting at $i (where $data[$i] is ESC),
     * or -1 if the sequence is not yet complete within $data.
     */
    private function escapeLength(string $data, int $i): int
    {
        $len = strlen($data);
        if ($i + 1 >= $len) {
            return -1; // lone ESC so far
        }

        $next = $data[$i + 1];

        if ($next === '[') { // CSI: ESC [ params final(0x40-0x7e)
            for ($j = $i + 2; $j < $len; $j++) {
                $b = ord($data[$j]);
                if ($b >= 0x40 && $b <= 0x7E) {
                    return $j - $i + 1;
                }
            }

            return -1;
        }

        if ($next === ']') { // OSC: ESC ] ... (BEL | ESC \)
            for ($j = $i + 2; $j < $len; $j++) {
                if (ord($data[$j]) === 0x07) {
                    return $j - $i + 1;
                }
                if ($data[$j] === "\x1b") {
                    if ($j + 1 >= $len) {
                        return -1; // possible ST split across the boundary
                    }
                    if ($data[$j + 1] === '\\') {
                        return $j - $i + 2;
                    }
                }
            }

            return -1;
        }

        return 2; // ESC + a single following byte (charset selects, etc.)
    }
}
