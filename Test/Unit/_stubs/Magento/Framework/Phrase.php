<?php

/**
 * Unit-test stub of Magento\Framework\Phrase (#225).
 *
 * Declares only the surface the unit tests and the code under test use,
 * with Magento's signatures. Loaded by Test/Unit/bootstrap.php only when
 * the real class is not installed. Not shipped (export-ignore).
 */

declare(strict_types=1);

namespace Magento\Framework;

/**
 * Translatable phrase. Renders positional `%1`, `%2` and named `%key`
 * placeholders; no translation lookup.
 */
class Phrase
{
    /**
     * @param string                   $text
     * @param array<int|string, mixed> $arguments
     */
    public function __construct(private $text, private array $arguments = [])
    {
    }

    /**
     * @return string
     */
    public function render()
    {
        $replace = [];
        foreach ($this->arguments as $key => $value) {
            $replace['%' . (is_int($key) ? $key + 1 : $key)] = (string) $value;
        }

        return strtr((string) $this->text, $replace);
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->render();
    }
}
