<?php
/**
 * Signing of the connector's releases, on the agency's computer only.
 *
 *     php bin/release.php keys              Create the main key (encrypted by a passphrase) and the backup key (on paper).
 *     php bin/release.php sign v0.16.0      Build and sign the zip of a tag, in dist/.
 *     php bin/release.php sign v0.16.0 --backup   The same, with the backup key typed from its sheet.
 *     php bin/release.php verify dist/fred-espace-client-0.16.0.zip   Check a zip as a site would.
 *
 * The main key lives in %USERPROFILE%\.hop-connector\release-main.key (or
 * HOP_RELEASE_HOME), encrypted by the passphrase: the file alone signs
 * nothing. Never on GitHub, never on a server.
 *
 * @package Fred_Espace_Client
 */

// phpcs:disable -- A command-line tool run on the agency's computer, never loaded by WordPress.

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

const MAIN_NAME   = 'principale-2026';
const BACKUP_NAME = 'secours-2026';
const SLUG        = 'fred-espace-client';

$root = dirname( __DIR__ );
$args = array_slice( $argv, 1 );

try {
	switch ( $args[0] ?? '' ) {
		case 'keys':
			create_keys();
			break;
		case 'sign':
			sign( $root, (string) ( $args[1] ?? '' ), in_array( '--backup', $args, true ) );
			break;
		case 'verify':
			$checked = verify_zip( (string) ( $args[1] ?? '' ), allowed_keys( file_get_contents( $root . '/includes/class-fred-espace-client-release.php' ) ) );
			say( "Signature valide : version {$checked['version']}, clé {$checked['key']}." );
			break;
		default:
			say( 'Usage : php bin/release.php keys | sign <tag> [--backup] | verify <zip>' );
			exit( 1 );
	}
} catch ( RuntimeException $exception ) {
	fwrite( STDERR, 'Erreur : ' . $exception->getMessage() . PHP_EOL );
	exit( 1 );
}

/**
 * Create both keys, once.
 */
function create_keys(): void {
	$file = key_file();

	if ( is_file( $file ) ) {
		throw new RuntimeException( "La clé principale existe déjà : {$file}. Rien n'est remplacé." );
	}

	say( 'Choisissez une phrase de passe : quatre ou cinq mots au hasard, au moins 20 caractères.' );
	say( 'Elle ne s’écrit nulle part. Sans elle, la clé principale ne signe rien.' );
	$passphrase = ask_hidden( 'Phrase de passe' );

	if ( strlen( $passphrase ) < 20 ) {
		throw new RuntimeException( 'La phrase de passe a moins de 20 caractères.' );
	}

	if ( ask_hidden( 'La même phrase, encore une fois' ) !== $passphrase ) {
		throw new RuntimeException( 'Les deux phrases ne sont pas identiques.' );
	}

	$main        = sodium_crypto_sign_keypair();
	$backup_seed = random_bytes( SODIUM_CRYPTO_SIGN_SEEDBYTES );
	$backup      = sodium_crypto_sign_seed_keypair( $backup_seed );
	$salt        = random_bytes( SODIUM_CRYPTO_PWHASH_SALTBYTES );
	$nonce       = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
	$box_key     = derive( $passphrase, $salt, SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE );

	if ( ! is_dir( dirname( $file ) ) ) {
		mkdir( dirname( $file ), 0700, true );
	}

	file_put_contents(
		$file,
		json_encode(
			array(
				'name'          => MAIN_NAME,
				'public'        => base64_encode( sodium_crypto_sign_publickey( $main ) ),
				'backup_name'   => BACKUP_NAME,
				'backup_public' => base64_encode( sodium_crypto_sign_publickey( $backup ) ),
				'salt'          => base64_encode( $salt ),
				'nonce'         => base64_encode( $nonce ),
				'ops'           => SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE,
				'mem'           => SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE,
				'box'           => base64_encode( sodium_crypto_secretbox( sodium_crypto_sign_secretkey( $main ), $nonce, $box_key ) ),
			),
			JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
		)
	);

	$sheet = ( getenv( 'USERPROFILE' ) ?: getenv( 'HOME' ) ) . DIRECTORY_SEPARATOR . 'Downloads' . DIRECTORY_SEPARATOR . 'cle-de-secours-connecteur.txt';
	file_put_contents(
		$sheet,
		implode(
			PHP_EOL,
			array(
				'CLÉ DE SECOURS DU CONNECTEUR ESPACE CLIENT (' . BACKUP_NAME . ')',
				'Créée le ' . gmdate( 'Y-m-d' ) . '. À imprimer, à ranger hors de l’ordinateur, puis à effacer de l’ordinateur.',
				'',
				'Elle sert seulement si la clé principale est perdue, volée, ou si la phrase de passe est oubliée :',
				'php bin/release.php sign <tag> --backup, puis taper les 64 caractères ci-dessous (les espaces ne comptent pas).',
				'',
				'    ' . implode( ' ', str_split( bin2hex( $backup_seed ), 8 ) ),
				'',
				'Clé publique (pour vérifier) : ' . base64_encode( sodium_crypto_sign_publickey( $backup ) ),
				'',
			)
		)
	);

	$main_public   = base64_encode( sodium_crypto_sign_publickey( $main ) );
	$backup_public = base64_encode( sodium_crypto_sign_publickey( $backup ) );
	sodium_memzero( $main );
	sodium_memzero( $backup_seed );

	say( '' );
	say( "Clé principale chiffrée : {$file}" );
	say( '  Copiez ce fichier sur une clé USB, en plus de l’ordinateur.' );
	say( "Clé de secours : {$sheet}" );
	say( '  Imprimez cette feuille, rangez-la, puis effacez le fichier (et videz la corbeille).' );
	say( '' );
	say( 'Clés publiques, à mettre dans le connecteur et dans la plateforme :' );
	say( "  '" . MAIN_NAME . "'   => '{$main_public}'," );
	say( "  '" . BACKUP_NAME . "' => '{$backup_public}'," );
}

/**
 * Build the zip of a tag, sign it, check it.
 */
function sign( string $root, string $tag, bool $with_backup ): void {
	if ( ! preg_match( '/^v?(\d+\.\d+\.\d+(?:-[\w.]+)?)$/', $tag, $match ) ) {
		throw new RuntimeException( 'Indiquez le tag à signer, par exemple v0.16.0.' );
	}

	$version = $match[1];
	$work    = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hop-release-' . bin2hex( random_bytes( 6 ) );
	$archive = $work . '.zip';

	run( 'git -C ' . escapeshellarg( $root ) . ' archive --format=zip --prefix=' . SLUG . '/ -o ' . escapeshellarg( $archive ) . ' ' . escapeshellarg( $tag ) );

	$zip = new ZipArchive();
	if ( true !== $zip->open( $archive ) || ! $zip->extractTo( $work ) ) {
		throw new RuntimeException( 'Le zip du tag ne s’ouvre pas.' );
	}
	$zip->close();
	unlink( $archive );

	$folder = $work . '/' . SLUG;
	$keys   = allowed_keys( (string) file_get_contents( $folder . '/includes/class-fred-espace-client-release.php' ) );

	if ( header_version( $folder . '/fred-espace-client.php' ) !== $version ) {
		throw new RuntimeException( "La version de fred-espace-client.php n'est pas {$version} dans ce tag." );
	}

	[ $name, $secret ] = $with_backup ? backup_key() : main_key();

	if ( ! isset( $keys[ $name ] ) || base64_decode( $keys[ $name ] ) !== sodium_crypto_sign_publickey_from_secretkey( $secret ) ) {
		throw new RuntimeException( "La clé « {$name} » ne figure pas dans les clés que cette version accepte : les sites la refuseraient au tour suivant." );
	}

	$files = hashes( $folder );
	ksort( $files );
	$manifest = json_encode(
		array(
			'plugin'  => SLUG,
			'version' => $version,
			'files'   => $files,
		),
		JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
	) . "\n";

	file_put_contents( $folder . '/release.json', $manifest );
	file_put_contents(
		$folder . '/release.sig',
		json_encode(
			array(
				'key'       => $name,
				'signature' => base64_encode( sodium_crypto_sign_detached( $manifest, $secret ) ),
			),
			JSON_PRETTY_PRINT
		) . "\n"
	);
	sodium_memzero( $secret );

	$dist = $root . '/dist';
	if ( ! is_dir( $dist ) ) {
		mkdir( $dist );
	}
	$out = $dist . '/' . SLUG . '-' . $version . '.zip';
	if ( is_file( $out ) ) {
		unlink( $out );
	}

	$zip = new ZipArchive();
	$zip->open( $out, ZipArchive::CREATE );
	foreach ( array_merge( array_keys( $files ), array( 'release.json', 'release.sig' ) ) as $path ) {
		$zip->addFile( $folder . '/' . $path, SLUG . '/' . $path );
	}
	$zip->close();
	remove( $work );

	$checked = verify_zip( $out, $keys );
	say( "Signé avec la clé {$checked['key']} : {$out}" );
}

/**
 * Check a release zip with the given keys, as a site does.
 *
 * @param  array<string, string> $keys  Allowed public keys, by name.
 * @return array{version:string,key:string}
 */
function verify_zip( string $zip_file, array $keys ): array {
	$work = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hop-verify-' . bin2hex( random_bytes( 6 ) );
	$zip  = new ZipArchive();

	if ( true !== $zip->open( $zip_file ) || ! $zip->extractTo( $work ) ) {
		throw new RuntimeException( "Le zip ne s'ouvre pas : {$zip_file}" );
	}
	$zip->close();

	try {
		$folder   = $work . '/' . SLUG;
		$manifest = (string) @file_get_contents( $folder . '/release.json' );
		$envelope = json_decode( (string) @file_get_contents( $folder . '/release.sig' ), true );
		$name     = (string) ( $envelope['key'] ?? '' );
		$sig      = base64_decode( (string) ( $envelope['signature'] ?? '' ), true );

		if ( '' === $manifest || ! isset( $keys[ $name ] ) || false === $sig || strlen( $sig ) !== SODIUM_CRYPTO_SIGN_BYTES
			|| ! sodium_crypto_sign_verify_detached( $sig, $manifest, base64_decode( $keys[ $name ] ) ) ) {
			throw new RuntimeException( 'Signature absente ou invalide.' );
		}

		$release = json_decode( $manifest, true );
		$files   = $release['files'];
		$found   = hashes( $folder );
		ksort( $files );
		ksort( $found );

		if ( $files !== $found || header_version( $folder . '/fred-espace-client.php' ) !== $release['version'] ) {
			throw new RuntimeException( 'Les fichiers du zip ne sont pas ceux qui ont été signés.' );
		}

		return array(
			'version' => (string) $release['version'],
			'key'     => $name,
		);
	} finally {
		remove( $work );
	}
}

/**
 * The main key, decrypted by the passphrase.
 *
 * @return array{0:string,1:string}
 */
function main_key(): array {
	$file = key_file();
	$data = is_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;

	if ( ! is_array( $data ) ) {
		throw new RuntimeException( "Clé principale introuvable : {$file}. Copiez-la depuis la clé USB, ou signez avec --backup." );
	}

	$box_key = derive( ask_hidden( 'Phrase de passe de la clé principale' ), base64_decode( $data['salt'] ), (int) $data['ops'], (int) $data['mem'] );
	$secret  = sodium_crypto_secretbox_open( base64_decode( $data['box'] ), base64_decode( $data['nonce'] ), $box_key );

	if ( false === $secret ) {
		throw new RuntimeException( 'Phrase de passe incorrecte.' );
	}

	return array( (string) $data['name'], $secret );
}

/**
 * The backup key, typed from its sheet.
 *
 * @return array{0:string,1:string}
 */
function backup_key(): array {
	$hex = strtolower( (string) preg_replace( '/\s+/', '', ask_hidden( 'Les 64 caractères de la clé de secours' ) ) );

	if ( ! preg_match( '/^[0-9a-f]{64}$/', $hex ) ) {
		throw new RuntimeException( 'La clé de secours compte 64 caractères, chiffres et lettres de a à f.' );
	}

	return array( BACKUP_NAME, sodium_crypto_sign_secretkey( sodium_crypto_sign_seed_keypair( hex2bin( $hex ) ) ) );
}

/**
 * Keys allowed by a version of the connector, read from its source.
 *
 * @return array<string, string>
 */
function allowed_keys( string $source ): array {
	if ( ! preg_match( '/const KEYS = array\((.*?)\);/s', $source, $block ) ) {
		throw new RuntimeException( 'Liste des clés introuvable dans class-fred-espace-client-release.php.' );
	}

	preg_match_all( "/'([\w-]+)'\s*=>\s*'([A-Za-z0-9+\/=]{44})'/", $block[1], $pairs, PREG_SET_ORDER );

	return array_column( $pairs, 2, 1 );
}

/**
 * SHA-256 of every file under a folder, without the signature files.
 *
 * @return array<string, string>
 */
function hashes( string $folder ): array {
	$hashes = array();

	foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $folder, FilesystemIterator::SKIP_DOTS ) ) as $item ) {
		$path = ltrim( substr( str_replace( '\\', '/', $item->getPathname() ), strlen( str_replace( '\\', '/', $folder ) ) ), '/' );

		if ( $item->isFile() && 'release.json' !== $path && 'release.sig' !== $path ) {
			$hashes[ $path ] = hash_file( 'sha256', $item->getPathname() );
		}
	}

	return $hashes;
}

function header_version( string $file ): string {
	return preg_match( '/^[ \t\/*#@]*Version:\s*(\S+)/mi', (string) @file_get_contents( $file ), $match ) ? $match[1] : '';
}

function derive( string $passphrase, string $salt, int $ops, int $mem ): string {
	return sodium_crypto_pwhash( SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $passphrase, $salt, $ops, $mem, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13 );
}

function key_file(): string {
	$home = getenv( 'HOP_RELEASE_HOME' ) ?: ( ( getenv( 'USERPROFILE' ) ?: getenv( 'HOME' ) ) . DIRECTORY_SEPARATOR . '.hop-connector' );

	return $home . DIRECTORY_SEPARATOR . 'release-main.key';
}

/**
 * Read a line without showing it. HOP_RELEASE_PASSPHRASE answers instead,
 * for tests only.
 */
function ask_hidden( string $prompt ): string {
	$given = getenv( 'HOP_RELEASE_PASSPHRASE' );
	if ( false !== $given && '' !== $given ) {
		return $given;
	}

	if ( DIRECTORY_SEPARATOR === '\\' ) {
		$command = 'powershell -NoProfile -Command "$s = Read-Host -AsSecureString ' . escapeshellarg( $prompt ) . '; '
			. '[Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($s))"';

		return rtrim( (string) shell_exec( $command ), "\r\n" );
	}

	fwrite( STDOUT, $prompt . ' : ' );
	shell_exec( 'stty -echo' );
	$line = rtrim( (string) fgets( STDIN ), "\r\n" );
	shell_exec( 'stty echo' );
	fwrite( STDOUT, PHP_EOL );

	return $line;
}

function run( string $command ): void {
	exec( $command . ' 2>&1', $output, $code );

	if ( 0 !== $code ) {
		throw new RuntimeException( implode( PHP_EOL, $output ) );
	}
}

function remove( string $path ): void {
	if ( is_dir( $path ) && ! is_link( $path ) ) {
		foreach ( scandir( $path ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry ) {
				remove( $path . DIRECTORY_SEPARATOR . $entry );
			}
		}
		rmdir( $path );
	} elseif ( file_exists( $path ) || is_link( $path ) ) {
		unlink( $path );
	}
}

function say( string $line ): void {
	fwrite( STDOUT, $line . PHP_EOL );
}
