<?php
/**
 * WP SMIME, a WordPress interface to S/MIME
 *
 * @license https://www.gnu.org/licenses/gpl-3.0.en.html
 *
 * @copyright Copyright (c) 2017 by Meitar "maymay" Moscovitz
 *
 * @package WordPress\Plugin\WP_PGP_Encrypted_Emails\WP_OpenPGP
 */

if ( ! defined( 'ABSPATH' ) ) { exit; } // Disallow direct HTTP access.

/**
 * Main class for S/MIME operations in WordPress.
 */
class WP_SMIME {

    /**
     * S/MIME-specific MIME parameters for the Content-Type header
     * added by PHP's `openssl_pkcs7_encrypt()` function.
     *
     * This is used to store the current mail message's overrides to
     * WordPress's default `Content-Type` header processing, which is
     * sadly rather naive.
     *
     * @var string
     *
     * @see wp_mail()
     * @see self::encrypt()
     * @see self::filterContentType()
     */
    private static $media_type_parameters;

    /**
     * Registers WordPress plugin API hooks for other plugins.
     */
    public static function register () {
        add_filter( 'smime_certificate', array( __CLASS__, 'getCertificate' ) );
        add_filter( 'smime_certificate_pem_encode', array( __CLASS__, 'pemEncode' ) );
        add_filter( 'smime_pem_to_der', array( __CLASS__, 'pemToDer' ) );
        add_filter( 'smime_encrypt', array( __CLASS__, 'encrypt'), 10, 4 );
    }

    /**
     * Gets an X.509 Certificate handle.
     *
     * @param mixed $cert The certificate.
     *
     * @see https://secure.php.net/manual/en/openssl.certparams.php
     *
     * @return resource|FALSE
     */
    public static function getCertificate ( $cert ) {
        $r = @openssl_x509_read( $cert );
        if ( is_resource( $r ) && 'OpenSSL X.509' === get_resource_type( $r ) ) {
            return $r;
        }
        return false;
    }

    /**
     * Encodes ("exports") a given X.509 certificate as PEM format.
     *
     * @param resource $cert
     *
     * @return string|FALSE
     */
    public static function pemEncode ( $cert ) {
        $r = null;
        return ( openssl_x509_export( $cert, $r ) )
            ? $r
            : false;
    }

    /**
     * Encodes a PEM-encoded (RFC 7468) string to its DER equivalent.
     *
     * PEM is two things: a header/footer labeling and a Base 64
     * encoding. Therefore, to go from a valid PEM format back to DER
     * (raw binary) representation of the same data, one need merely
     * strip the labels and base-64 decode the data. The process does
     * not verify the data is actually valid DER data, just that the
     * representation of it is correct.
     *
     * This means that if your input PEM data is a string containing
     * multiple objects (i.e., it has more than one pair of labels),
     * then this method may not actually work for your use case. For
     * safety, you should call this function only on a single object,
     * like one (and only one) certificate, or key, at a time.
     *
     * @see https://tools.ietf.org/html/rfc7468
     * @see https://en.wikipedia.org/wiki/X.690#DER_encoding
     *
     * @param string $pem_str Data that is PEM-encoded.
     *
     * @return string The same data, but in DER format.
     */
    public static function pemToDer ( $pem_str ) {
        $pem_lines = array_map( 'trim', explode( "\n", $pem_str ) );
        $der_lines = array();

        // Remove any lines that begin with five dashes.
        // (These are labels.)
        foreach ( $pem_lines as $pem_line ) {
            if ( 0 === strpos( $pem_line, '-----' ) ) {
                continue;
            } else {
                $der_lines[] = $pem_line;
            }
        }

        return base64_decode( implode( '', $der_lines ) );
    }

    /**
     * Encrypts a message as an S/MIME email given a public certificate.
     *
     * @param string $message The message contents to encrypt.
     * @param string|string[] $headers The message headers for the encrypted part.
     * @param resource|array $certificates The recipient's certificate, or an array of recipient certificates.
     *
     * @return array|FALSE An array with two keys, `headers` and `message`, wherein the message is encrypted.
     */
    public static function encrypt ( $message, $headers, $certificates ) {
        $infile  = tempnam( sys_get_temp_dir(), 'wp_email_' );
        $outfile = tempnam( sys_get_temp_dir(), 'wp_email_' );

        $plaintext  = ( is_array( $headers ) ) ? implode( "\n", $headers ) : $headers;
        $plaintext .= "\n\n" . $message;

        // If we have it available, use a better cipher than the default.
        // This will be available in PHP 5.4 or later.
        // See https://secure.php.net/manual/en/openssl.ciphers.php
        $cipher_id = ( defined( 'OPENSSL_CIPHER_AES_256_CBC' ) ) ? OPENSSL_CIPHER_AES_256_CBC : OPENSSL_CIPHER_3DES;

        if ( is_string( $headers ) ) {
            // PHP's openssl_pkcs7_encrypt expects headers as an array.
            $headers = explode( "\n", $headers );
        }

        // Write files for OpenSSL's encryption (which takes a file path).
        file_put_contents( $infile, $plaintext );

        // Do the encryption.
        $encrypted = openssl_pkcs7_encrypt(
            $infile,
            $outfile,
            $certificates,
            // Remove any existing 'Content-Type: text/html' headers,
            // since `openssl_pkcs7_encrypt() generates its own and we
            // do not want to prepend these; they are intended for the
            // encrypted body, not the envelope.
            array_filter( $headers, array( __CLASS__, 'filterMailHeader' ) ),
            0,
            $cipher_id
        );
        if ( $encrypted ) {
            $smime = file_get_contents( $outfile );
        }

        // Immediately overwrite and delete the files written to disk.
        if ( class_exists('\Shred\Shred') ) {
            $shredder = new \Shred\Shred();
            foreach ( array( $infile, $outfile ) as $f ) {
                if ( ! $shredder->shred( $f ) ) {
                    error_log( sprintf(
                        __( 'Failed to shred file: %1$s', 'wp-pgp-encrypted-emails' ),
                        $f
                    ) );
                }
            }
        } else {
            $fs = (int) filesize( $infile ); // cast to int to avoid FALSE
            file_put_contents( $infile, random_bytes( $fs + random_int( 0, $fs * 2 ) ) );
            unlink( $infile );
            $fs = (int) filesize( $outfile );
            file_put_contents( $outfile, random_bytes( $fs + random_int( 0, $fs * 2 ) ) );
            unlink( $outfile );
        }

        if ( $smime ) {
            $parts = explode( "\n\n", $smime, 2 );
            $r = array(
                'headers' => $parts[0],
                'message' => $parts[1],
            );
            // WordPress doesn't like MIME headers that have complex
            // or unrecognized media type parameters, so we utilize
            // its `wp_mail_content_type` filter hook to stuff the
            // complete Content-Type header, with parameters, there.
            $m = array();
            if ( preg_match( '/Content-Type: application\/(?:x-)?pkcs7-mime(.*)/i', $r['headers'], $m ) ) {
                if ( isset( $m[1] ) ) {
                    self::$media_type_parameters = $m[1];
                    add_filter( 'wp_mail_content_type', array( __CLASS__, 'filterContentType' ) );
                }
            }
        } else {
            $r = false;
        }

        return $r;
    }

    /**
     * Filters an array of email headers
     *
     * When used with `array_filter()`, this function will remove
     * headers that contain the string `Content-Type: text/html`,
     * Empty elements (blank lines) are also removed.
     *
     * @param $h string The header line to filter.
     *
     * @return bool true if line is not filtered out, false otherwise.
     */
    private static function filterMailHeader( $h ) {
        return $h && false === stripos( $h, 'Content-Type: text/html' );
    }

    /**
     * Ensures S/MIME emails contain the correct Content-Type MIME
     * header as supplied by the underlying `openssl_pkcs7_encrypt()`
     * function call result.
     *
     * @param string $content_type
     *
     * @see https://developer.wordpress.org/reference/hooks/wp_mail_content_type/
     *
     * @uses self::$media_type_parameters
     */
    public static function filterContentType ( $content_type ) {
        // Retrieve the last `encrypt()`ion's media type result.
        $parameters = self::$media_type_parameters;

        // Don't retain this information for future invocations.
        self::$media_type_parameters = null;

        // Unhook ourselves.
        remove_filter( 'wp_mail_content_type', array( __CLASS__, 'filterContentType' ) );

        return $content_type . $parameters;
    }
}
