<?php

namespace Goldnead\PrivateMedia\Delivery;

/**
 * One byte range out of a `Range` header, or the decision to send it all.
 *
 * What is answered how (RFC 9110 §14):
 *
 * | Header                       | Answer                                  |
 * |------------------------------|-----------------------------------------|
 * | none, not `bytes=`, garbage  | 200, whole file                         |
 * | `bytes=a-b`, a ≤ b, a < size | 206, a … min(b, size − 1)               |
 * | `bytes=a-`, a < size         | 206, a … size − 1                       |
 * | `bytes=-n`, n > 0            | 206, the last min(n, size) bytes        |
 * | `bytes=a-b` with a > b       | 200, whole file (invalid spec, ignored) |
 * | `bytes=a-…` with a ≥ size    | 416, `Content-Range: bytes * /size`     |
 * | `bytes=-0`                   | 416                                     |
 * | several ranges `a-b,c-d`     | 200, whole file (no multipart)          |
 *
 * Multipart/byteranges is left out on purpose: no video player asks for it,
 * and the RFC lets a server ignore a Range header it does not want to serve.
 */
final class ByteRange
{
    private function __construct(
        public readonly int $start,
        public readonly int $end,
        public readonly int $status,
    ) {}

    public static function resolve(?string $header, int $size): self
    {
        $whole = new self(0, max(0, $size - 1), 200);

        if (! is_string($header) || $size <= 0) {
            return $whole;
        }

        if (preg_match('/^\s*bytes\s*=\s*(\d*)\s*-\s*(\d*)\s*$/i', $header, $m) !== 1) {
            return $whole;
        }

        [, $from, $to] = $m;

        if ($from === '' && $to === '') {
            return $whole;
        }

        if ($from === '') {
            $suffix = self::toInt($to);

            if ($suffix === 0) {
                return self::unsatisfiable($size);
            }

            return new self(max(0, $size - $suffix), $size - 1, 206);
        }

        $start = self::toInt($from);
        $end = $to === '' ? PHP_INT_MAX : self::toInt($to);

        if ($end < $start) {
            return $whole;
        }

        if ($start >= $size) {
            return self::unsatisfiable($size);
        }

        return new self($start, min($end, $size - 1), 206);
    }

    public function length(): int
    {
        return $this->end - $this->start + 1;
    }

    public function isPartial(): bool
    {
        return $this->status === 206;
    }

    public function isSatisfiable(): bool
    {
        return $this->status !== 416;
    }

    /**
     * The first request of a playback, the one worth an audit row: the whole
     * file, or a range from byte 0.
     */
    public function isOpening(): bool
    {
        return $this->start === 0;
    }

    private static function unsatisfiable(int $size): self
    {
        return new self($size, $size - 1, 416);
    }

    /**
     * Digits only reach here. A number too long for an int is clamped rather
     * than wrapped, so `bytes=99999999999999999999-` reads as "past the end".
     */
    private static function toInt(string $digits): int
    {
        $digits = ltrim($digits, '0');

        if ($digits === '') {
            return 0;
        }

        if (strlen($digits) >= strlen((string) PHP_INT_MAX)) {
            return PHP_INT_MAX;
        }

        return (int) $digits;
    }
}
