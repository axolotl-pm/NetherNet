<?php

/*
 * This file is part of NetherNet.
 * Copyright (C) 2026 Axolotl Team <https://github.com/axolotl-pm/NetherNet>
 *
 * NetherNet is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

declare(strict_types=1);

namespace pocketmine\nethernet\identity;

use PHPUnit\Framework\TestCase;
use pocketmine\nethernet\crypto\Base64Url;
use pocketmine\nethernet\sdp\Fingerprint;
use function base64_decode;
use function base64_encode;
use function json_decode;
use function json_encode;
use function openssl_pkey_get_details;
use function str_pad;
use function strlen;
use function substr;
use function time;
use const STR_PAD_LEFT;

final class IdentityTest extends TestCase{

	private static function fingerprint() : Fingerprint{
		return new Fingerprint("sha-256", "00:11:22:33:44:55:66:77");
	}

	/**
	 * The whole point of the assertion: a peer holding the key can prove it also
	 * terminates the DTLS session this fingerprint describes.
	 */
	public function testAssertionProvesTheKeyThatSignedThisConnection() : void{
		$identity = ServerIdentity::generate();
		$assertion = (new SelfSignedIdentityProvider($identity, "self"))->issue(self::fingerprint());

		self::assertTrue($assertion->verifyFingerprint(self::fingerprint())->equals($identity->getPublicKey()));
	}

	/**
	 * The payload is rebuilt locally rather than taken from the peer, so an
	 * assertion lifted from another connection cannot be replayed onto this one.
	 */
	public function testAssertionForADifferentFingerprintIsRefused() : void{
		$assertion = (new SelfSignedIdentityProvider(ServerIdentity::generate()))->issue(self::fingerprint());

		$this->expectException(\pocketmine\nethernet\crypto\CryptoException::class);
		$assertion->verifyFingerprint(new Fingerprint("sha-256", "FF:FF:FF:FF"));
	}

	public function testAssertionSurvivesEncodingAndParsing() : void{
		$identity = ServerIdentity::generate();
		$original = (new SelfSignedIdentityProvider($identity, "example.test"))->issue(self::fingerprint());

		$parsed = IdentityAssertion::parse($original->encode());

		self::assertSame("example.test", $parsed->getDomain());
		self::assertSame(IdentityAssertion::PROTOCOL_DEFAULT, $parsed->getProtocol());
		self::assertSame($original->getRawToken(), $parsed->getRawToken());
		self::assertTrue($parsed->verifyFingerprint(self::fingerprint())->equals($identity->getPublicKey()));
	}

	/**
	 * The envelope carries the assertion as a JSON string rather than a nested
	 * object. Flattening it produces something no vanilla peer will parse, and the
	 * mistake is invisible until a real client refuses to connect.
	 */
	public function testEnvelopeNestsTheAssertionAsAString() : void{
		$encoded = (new SelfSignedIdentityProvider(ServerIdentity::generate()))->issue(self::fingerprint())->encode();

		$envelope = base64_decode($encoded, true);
		self::assertIsString($envelope);
		$decoded = json_decode($envelope, associative: true);

		self::assertIsArray($decoded);
		self::assertIsString($decoded["assertion"], "the assertion member must be a string holding JSON");
		self::assertIsArray($decoded["idp"]);

		$inner = json_decode($decoded["assertion"], associative: true);
		self::assertIsArray($inner);
		self::assertIsString($inner["token"]);
		self::assertIsString($inner["fingerprints"]);
	}

	/** A host token vouches for itself, which is what makes pinning its key useful. */
	public function testHostTokenIsSelfSigned() : void{
		$assertion = (new SelfSignedIdentityProvider(ServerIdentity::generate()))->issue(self::fingerprint());

		$this->expectNotToPerformAssertions();
		$assertion->getToken()->verifySelfSigned();
	}

	/**
	 * Minecraft switched the cpk claim from a base64 SubjectPublicKeyInfo to a JWK
	 * in v1.26.40, and clients on either side of that are both in the wild, so
	 * both encodings have to name the same key.
	 */
	public function testBothCpkEncodingsProduceTheSameKey() : void{
		$identity = ServerIdentity::generate();
		$expected = $identity->getPublicKey();

		$details = openssl_pkey_get_details($identity->getPrivateKey());
		self::assertIsArray($details);
		self::assertIsArray($details["ec"]);
		self::assertIsString($details["ec"]["x"]);
		self::assertIsString($details["ec"]["y"]);

		$fromJwk = PublicKey::fromClaim([
			"kty" => "EC",
			"crv" => "P-384",
			"x" => Base64Url::encode(str_pad($details["ec"]["x"], 48, "\x00", STR_PAD_LEFT)),
			"y" => Base64Url::encode(str_pad($details["ec"]["y"], 48, "\x00", STR_PAD_LEFT))
		]);
		$fromBase64 = PublicKey::fromClaim($expected->toCpk());

		self::assertTrue($fromJwk->equals($expected));
		self::assertTrue($fromBase64->equals($expected));
	}

	/**
	 * @dataProvider unusableClaimProvider
	 */
	public function testUnusableCpkClaimIsRefused(mixed $claim) : void{
		$this->expectException(\pocketmine\nethernet\crypto\CryptoException::class);
		PublicKey::fromClaim($claim);
	}

	/**
	 * @return mixed[][]
	 * @phpstan-return array<string, array{mixed}>
	 */
	public static function unusableClaimProvider() : array{
		return [
			"null" => [null],
			"number" => [1],
			"not base64" => ["!!!"],
			"base64 of nothing useful" => ["AAAA"],
			"wrong curve" => [["kty" => "EC", "crv" => "P-256", "x" => "AA", "y" => "AA"]],
			"symmetric key" => [["kty" => "oct", "k" => "AAAA"]],
			"coordinates of the wrong length" => [["kty" => "EC", "crv" => "P-384", "x" => "AA", "y" => "AA"]]
		];
	}

	/**
	 * The verifier's job is to bind an identity to this connection, so a peer that
	 * sends none must be a deliberate configuration choice rather than a default.
	 */
	public function testAnonymousPeerIsRefusedUnlessAllowed() : void{
		$this->expectException(IdentityException::class);
		(new AssertionIdentityVerifier())->verify(null, self::fingerprint());
	}

	public function testAnonymousPeerIsAllowedWhenConfigured() : void{
		self::assertNull((new AssertionIdentityVerifier(allowAnonymous: true))->verify(null, self::fingerprint()));
	}

	public function testVerifierReturnsTheProvenKey() : void{
		$identity = ServerIdentity::generate();
		$assertion = (new SelfSignedIdentityProvider($identity, "example.test"))->issue(self::fingerprint());

		$verified = (new AssertionIdentityVerifier())->verify($assertion->encode(), self::fingerprint());

		self::assertNotNull($verified);
		self::assertTrue($verified->publicKey->equals($identity->getPublicKey()));
		self::assertSame("example.test", $verified->providerDomain);
	}

	/**
	 * A token verifier is the only place a host can reject a peer on who it claims
	 * to be, so its refusal has to stop the connection rather than be logged.
	 */
	public function testTokenVerifierCanRefuseAPeer() : void{
		$assertion = (new SelfSignedIdentityProvider(ServerIdentity::generate()))->issue(self::fingerprint());

		$verifier = new AssertionIdentityVerifier(tokenVerifier: new class implements TokenVerifier{
			public function check(JsonWebToken $token) : void{
				throw new IdentityException("not on the allowlist");
			}
		});

		$this->expectException(IdentityException::class);
		$verifier->verify($assertion->encode(), self::fingerprint());
	}

	/**
	 * A tampered signature must fail the check rather than be reported as a
	 * malformed assertion, since the two mean very different things to a host
	 * reading its logs.
	 */
	public function testTamperedFingerprintSignatureIsRefused() : void{
		$identity = ServerIdentity::generate();
		$assertion = (new SelfSignedIdentityProvider($identity))->issue(self::fingerprint());

		$envelope = base64_decode($assertion->encode(), true);
		self::assertIsString($envelope);
		$decoded = json_decode($envelope, associative: true);
		self::assertIsArray($decoded);
		self::assertIsString($decoded["assertion"]);

		$inner = json_decode($decoded["assertion"], associative: true);
		self::assertIsArray($inner);
		self::assertIsString($inner["fingerprints"]);

		//flip the last character of the signature segment
		$signature = $inner["fingerprints"];
		$inner["fingerprints"] = substr($signature, 0, strlen($signature) - 1) . ($signature[strlen($signature) - 1] === "A" ? "B" : "A");

		$decoded["assertion"] = json_encode($inner);
		$tampered = base64_encode((string) json_encode($decoded));

		$this->expectException(IdentityException::class);
		(new AssertionIdentityVerifier())->verify($tampered, self::fingerprint());
	}

	/**
	 * A token whose signature is nothing but bytes, naming a key the peer holds. Only a
	 * TokenVerifier stands between this and being taken at face value.
	 */
	private static function forgedToken(PublicKey $publicKey) : string{
		$cpk = $publicKey->toCpk();

		$header = Base64Url::encode((string) json_encode(["alg" => JsonWebSignature::ALGORITHM, "x5u" => $cpk]));
		$claims = Base64Url::encode((string) json_encode(["exp" => time() + 3600, "cpk" => $cpk, "xuid" => "2535000000000001"]));

		return $header . "." . $claims . "." . Base64Url::encode("not a signature");
	}

	private static function forgedAssertion(ServerIdentity $identity) : IdentityAssertion{
		return IdentityAssertion::create(
			"auth.minecraft.org",
			self::forgedToken($identity->getPublicKey()),
			JsonWebSignature::signDetached(self::fingerprint()->toCanonicalPayload(), $identity->getPrivateKey())
		);
	}

	/**
	 * Fingerprint binding says the peer holds the key it named, and stops there. PeerIdentity
	 * is written on that basis, so it has to stay true: a host reading the result as proof of
	 * who the peer is would be believing claims the peer wrote itself.
	 */
	public function testFingerprintBindingAloneAcceptsAForgedToken() : void{
		$assertion = self::forgedAssertion(ServerIdentity::generate());

		self::assertNotNull((new AssertionIdentityVerifier())->verify($assertion->encode(), self::fingerprint()));
	}

	/** Re-keying someone else's token breaks its signature, which is what this catches. */
	public function testSelfSignedVerifierRefusesATokenThatDidNotSignItself() : void{
		$assertion = self::forgedAssertion(ServerIdentity::generate());

		$this->expectException(IdentityException::class);
		(new AssertionIdentityVerifier(tokenVerifier: new SelfSignedTokenVerifier()))->verify($assertion->encode(), self::fingerprint());
	}

	public function testSelfSignedVerifierAcceptsAKeyThatSignedItsOwnToken() : void{
		$identity = ServerIdentity::generate();
		$assertion = (new SelfSignedIdentityProvider($identity, "self"))->issue(self::fingerprint());

		$verified = (new AssertionIdentityVerifier(tokenVerifier: new SelfSignedTokenVerifier()))->verify($assertion->encode(), self::fingerprint());

		self::assertNotNull($verified);
		self::assertTrue($verified->publicKey->equals($identity->getPublicKey()));
	}
}
