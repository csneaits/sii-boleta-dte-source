<?php

namespace BigFish\PDF417;

/**
 * Encodes data into PDF417 code words.
 *
 * This is the top level data encoder which assigns encoding to lower level
 * (byte, number, text) encoders.
 */
class DataEncoder
{
    private $encoders;
    private $defaultEncoder;

    public function __construct()
    {
        // Encoders sorted in order of preference
        $this->encoders = [
            new Encoders\NumberEncoder(),
            new Encoders\TextEncoder(),
            new Encoders\ByteEncoder(),
        ];

        // Default mode is Text
        $this->defaultEncoder = $this->encoders[1];
    }

    /**
     * Encodes given data into an array of PDF417 code words.
     *
     * Splits the input data into chains which can be encoded within the same
     * encoder. Then encodes each chain using an algorithm that avoids
     * unnecessary encoder switches for single characters.
     *
     * @param string $data The data to encode.
     */
    public function encode($data)
    {
        $chains = $this->splitToChains($data);

        // Add a switch code at the beginning if the first encoder to be used
        // is not the text encoder. Decoders by default start decoding as text.
        $firstEncoder = $chains[0][1];
        $addSwitchCode = (!($firstEncoder instanceof Encoders\TextEncoder));

        $codes = [];
        foreach ($chains as $chEnc) {
            list($chain, $encoder) = $chEnc;

            $encoded = $encoder->encode($chain, $addSwitchCode);
            foreach ($encoded as $code) {
                $codes[] = $code;
            }

            $addSwitchCode = true;
        }

        return $codes;
    }

    /**
     * Splits a string into chains (sub-strings) which can be encoded with the
     * same encoder while minimising unnecessary switching.
     *
     * @param  string $data String to split into chains.
     * @return array        An array of [$chain, $encoder] pairs.
     */
    private function splitToChains($data)
    {
        $chain = "";
        $chains = [];
        $encoder = $this->defaultEncoder;

        $length = strlen($data);
        for ($i = 0; $i < $length; $i++) {
            $char = $data[$i];
            $newEncoder = $this->getEncoder($char);

            if ($newEncoder !== $encoder) {
                $switch = true;

                if ($encoder->canEncode($char)) {
                    $runLength = 1;
                    for ($j = $i + 1; $j < $length; $j++) {
                        if ($this->getEncoder($data[$j]) !== $newEncoder) {
                            break;
                        }
                        $runLength++;
                    }

                    if ($runLength === 1) {
                        $switch = false;
                    }
                }

                if ($switch) {
                    if (!empty($chain)) {
                        $chains[] = [$chain, $encoder];
                        $chain = "";
                    }
                    $encoder = $newEncoder;
                }
            }

            $chain .= $char;
        }

        if (!empty($chain)) {
            $chains[] = [$chain, $encoder];
        }

        return $chains;
    }

    public function getEncoder($char)
    {
        foreach ($this->encoders as $encoder) {
            if ($encoder->canEncode($char)) {
                return $encoder;
            }
        }

        $ord = ord($char);
        throw new \Exception("Cannot encode character $char (ASCII $ord)");
    }

    private function encodeChain($chain, EncoderInterface $encoder, array &$codes)
    {
        if (empty($chain)) {
            return;
        }

        $encoded = $encoder->encode($chain);
        foreach ($encoded as $code) {
            $codes[] = $code;
        }
    }
}
