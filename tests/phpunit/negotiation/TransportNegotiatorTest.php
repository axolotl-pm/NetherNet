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

namespace pocketmine\nethernet\negotiation;

use PHPUnit\Framework\TestCase;
use pocketmine\nethernet\FakeMessageChannel;
use pocketmine\nethernet\FakePeerTransport;
use pocketmine\nethernet\FakeTransportFactory;
use pocketmine\nethernet\identity\AssertionIdentityVerifier;
use pocketmine\nethernet\identity\SelfSignedIdentityProvider;
use pocketmine\nethernet\identity\ServerIdentity;
use pocketmine\nethernet\protocol\ErrorCode;
use pocketmine\nethernet\protocol\Reliability;
use pocketmine\nethernet\RecordingLogger;
use pocketmine\nethernet\sdp\SessionDescription;
use pocketmine\nethernet\SdpFixture;
use pocketmine\nethernet\transport\ConnectionBudgetConfiguration;
use pocketmine\nethernet\transport\TransportState;
use pocketmine\nethernet\utils\InternetAddress;
use function array_fill;

final class TransportNegotiatorTest extends TestCase{

	private const GATHERING_TIMEOUT = 15.0;
	private const CHANNEL_TIMEOUT = 5.0;
	private const MAX_REMOTE_CANDIDATES = 4;

	private static ServerIdentity $identity;

	private FakeTransportFactory $factory;
	private TransportNegotiator $negotiator;

	public static function setUpBeforeClass() : void{
		self::$identity = ServerIdentity::generate();
	}

	protected function setUp() : void{
		$this->factory = new FakeTransportFactory();
		$this->negotiator = $this->createNegotiator();
	}

	private function createNegotiator(bool $allowAnonymous = true) : TransportNegotiator{
		return new TransportNegotiator(
			new SelfSignedIdentityProvider(self::$identity),
			new AssertionIdentityVerifier(allowAnonymous: $allowAnonymous),
			$this->factory,
			new ConnectionBudgetConfiguration(),
			self::GATHERING_TIMEOUT,
			self::CHANNEL_TIMEOUT,
			self::MAX_REMOTE_CANDIDATES,
			TransportNegotiator::DEFAULT_MAX_NEGOTIATIONS,
			TransportNegotiator::DEFAULT_MAX_NEGOTIATIONS_PER_ADDRESS,
			new RecordingLogger()
		);
	}

	private function transport() : FakePeerTransport{
		self::assertCount(1, $this->factory->created);

		return $this->factory->created[0];
	}

	/**
	 * Takes a negotiation as far as a signaled answer.
	 */
	private function answered(CandidateMode $mode = CandidateMode::BUNDLED) : Negotiation{
		$negotiation = $this->negotiator->beginNegotiation(SdpFixture::description(), "1234", $mode);
		$transport = $this->transport();
		$transport->gatheringComplete = true;
		$transport->localDescription = SdpFixture::description([SdpFixture::HOST_CANDIDATE]);
		$this->negotiator->tick(100.0);
		self::assertNotNull($negotiation->getAnswer());

		return $negotiation;
	}

	private function openChannels(FakePeerTransport $transport) : void{
		$transport->pendingChannels = [new FakeMessageChannel(Reliability::RELIABLE), new FakeMessageChannel(Reliability::UNRELIABLE)];
		$transport->remoteAddress = new InternetAddress("192.0.2.10", 5000, 4);
		$transport->state = TransportState::CONNECTED;
	}

	public function testOfferIsHandedToTheTransportWithoutItsIdentity() : void{
		$this->negotiator->beginNegotiation(SdpFixture::description(), "1234");

		$offer = $this->transport()->remoteOffer;
		self::assertNotNull($offer);
		self::assertNull(SessionDescription::parse($offer)->getIdentity());
	}

	/**
	 * An HTTP client gets exactly one response, so its answer has to carry every candidate.
	 */
	public function testBundledAnswerWaitsUntilGatheringIsComplete() : void{
		$negotiation = $this->negotiator->beginNegotiation(SdpFixture::description(), "1234");
		$transport = $this->transport();
		$transport->localDescription = SdpFixture::description();

		$this->negotiator->tick(100.0);
		self::assertNull($negotiation->getAnswer());

		$transport->localDescription = SdpFixture::description([SdpFixture::HOST_CANDIDATE]);
		$transport->gatheringComplete = true;
		$this->negotiator->tick(100.1);

		$answer = $negotiation->getAnswer();
		self::assertNotNull($answer);
		$parsed = SessionDescription::parse($answer);
		self::assertSame(1, $parsed->countCandidates());
		self::assertNotNull($parsed->getIdentity(), "the answer carries no identity assertion");
		self::assertFalse($negotiation->isFinished());
	}

	/**
	 * A LAN client is sent candidates one by one as they are found, so its answer goes out at once and
	 * carries none.
	 */
	public function testTrickleAnswerIsSentBeforeGatheringCompletesAndCarriesNoCandidates() : void{
		$negotiation = $this->negotiator->beginNegotiation(SdpFixture::description(), "1234", CandidateMode::TRICKLE);
		$transport = $this->transport();
		$transport->localDescription = SdpFixture::description([SdpFixture::HOST_CANDIDATE]);

		self::assertSame([], $negotiation->takeLocalCandidates(), "candidates were offered before the answer they belong to");

		$this->negotiator->tick(100.0);

		$answer = $negotiation->getAnswer();
		self::assertNotNull($answer);
		self::assertSame(0, SessionDescription::parse($answer)->countCandidates());

		$transport->localCandidates = [SdpFixture::HOST_CANDIDATE];
		$candidates = $negotiation->takeLocalCandidates();
		self::assertCount(1, $candidates);
		self::assertStringContainsString("192.168.1.20 51000 typ host", $candidates[0]);
		self::assertStringContainsString("ufrag abcd", $candidates[0]);
		self::assertSame([], $negotiation->takeLocalCandidates());
	}

	public function testBundledNegotiationNeverTricklesCandidates() : void{
		$negotiation = $this->answered();
		$this->transport()->localCandidates = [SdpFixture::HOST_CANDIDATE];

		self::assertSame([], $negotiation->takeLocalCandidates());
	}

	public function testGatheringThatNeverCompletesTimesOut() : void{
		$negotiation = $this->negotiator->beginNegotiation(SdpFixture::description(), "1234");

		$this->negotiator->tick(100.0);
		$this->negotiator->tick(100.0 + self::GATHERING_TIMEOUT - 0.01);
		self::assertFalse($negotiation->isFinished());

		$this->negotiator->tick(100.0 + self::GATHERING_TIMEOUT);
		self::assertTrue($negotiation->isFailed());
		self::assertSame(ErrorCode::ICE, $negotiation->getFailureCode());
		self::assertTrue($this->transport()->closed);
	}

	public function testPeerIsEstablishedOnceBothChannelsAreOpen() : void{
		$negotiation = $this->answered();
		$transport = $this->transport();

		$reliable = new FakeMessageChannel(Reliability::RELIABLE);
		$unreliable = new FakeMessageChannel(Reliability::UNRELIABLE);
		$unreliable->open = false;
		$transport->pendingChannels = [$reliable];
		$transport->remoteAddress = new InternetAddress("192.0.2.10", 5000, 4);

		$this->negotiator->tick(100.1);
		self::assertSame([], $this->negotiator->takeEstablished());

		$transport->pendingChannels = [$unreliable];
		$this->negotiator->tick(100.2);
		self::assertSame([], $this->negotiator->takeEstablished(), "a channel that has not opened yet was counted");

		$unreliable->open = true;
		$this->negotiator->tick(100.3);

		$established = $this->negotiator->takeEstablished();
		self::assertCount(1, $established);
		self::assertSame($transport, $established[0]->transport);
		self::assertSame($reliable, $established[0]->channels->reliable);
		self::assertSame($unreliable, $established[0]->channels->unreliable);
		self::assertSame("1234", $established[0]->networkId);
		self::assertSame("192.0.2.10", $established[0]->address->ip);
		self::assertTrue($negotiation->isFinished());
		self::assertFalse($negotiation->isFailed());
		self::assertFalse($transport->closed);

		self::assertSame([], $this->negotiator->takeEstablished(), "the same peer was handed over twice");
	}

	/**
	 * The channel timeout only starts once the peer has an answer to act on; time spent gathering is not
	 * held against it.
	 */
	public function testPeerThatNeverOpensItsChannelsTimesOut() : void{
		$negotiation = $this->negotiator->beginNegotiation(SdpFixture::description(), "1234");
		$transport = $this->transport();
		$this->negotiator->tick(100.0);

		$transport->gatheringComplete = true;
		$transport->localDescription = SdpFixture::description([SdpFixture::HOST_CANDIDATE]);
		$this->negotiator->tick(100.0 + self::GATHERING_TIMEOUT - 1.0);
		self::assertNotNull($negotiation->getAnswer());

		$this->negotiator->tick(200.0);
		$this->negotiator->tick(200.0 + self::CHANNEL_TIMEOUT - 0.01);
		self::assertFalse($negotiation->isFinished());

		$this->negotiator->tick(200.0 + self::CHANNEL_TIMEOUT);
		self::assertSame(ErrorCode::NEGOTIATION_TIMEOUT_WAITING_FOR_ACCEPT, $negotiation->getFailureCode());
		self::assertTrue($this->transport()->closed);
	}

	public function testChannelWithUnsupportedParametersFailsTheNegotiation() : void{
		$negotiation = $this->answered();
		$stray = new FakeMessageChannel(null, "SomethingElse");
		$this->transport()->pendingChannels = [$stray];

		$this->negotiator->tick(100.1);

		self::assertSame(ErrorCode::DATA_CHANNEL_CLOSED, $negotiation->getFailureCode());
		self::assertStringContainsString("SomethingElse", $negotiation->getFailureReason() ?? "");
		self::assertTrue($stray->closed);
	}

	public function testSecondChannelOfTheSameKindFailsTheNegotiation() : void{
		$negotiation = $this->answered();
		$this->transport()->pendingChannels = [new FakeMessageChannel(Reliability::RELIABLE), new FakeMessageChannel(Reliability::RELIABLE)];

		$this->negotiator->tick(100.1);

		self::assertSame(ErrorCode::DATA_CHANNEL_CLOSED, $negotiation->getFailureCode());
	}

	public function testChannelsOpenWithoutAPeerAddressFailTheNegotiation() : void{
		$negotiation = $this->answered();
		$transport = $this->transport();
		$this->openChannels($transport);
		$transport->remoteAddress = null;

		$this->negotiator->tick(100.1);

		self::assertSame(ErrorCode::ICE, $negotiation->getFailureCode());
		self::assertSame([], $this->negotiator->takeEstablished());
	}

	public function testTransportThatBreaksFailsTheNegotiation() : void{
		$negotiation = $this->negotiator->beginNegotiation(SdpFixture::description(), "1234");
		$this->transport()->state = TransportState::FAILED;

		$this->negotiator->tick(100.0);

		self::assertSame(ErrorCode::ICE, $negotiation->getFailureCode());
	}

	/**
	 * A signaling transport fails a negotiation when its own side of the exchange falls through. The transport
	 * is closed on the spot, and the negotiator has to cope with finding it that way on its next tick.
	 */
	public function testNegotiationFailedFromOutsideIsDroppedQuietly() : void{
		$negotiation = $this->negotiator->beginNegotiation(SdpFixture::description(), "1234");
		$negotiation->fail("client went away", ErrorCode::SIGNALING_UNKNOWN_ERROR);
		self::assertTrue($this->transport()->closed);

		$this->negotiator->tick(100.0);

		self::assertSame(ErrorCode::SIGNALING_UNKNOWN_ERROR, $negotiation->getFailureCode());
		self::assertSame("client went away", $negotiation->getFailureReason());
		self::assertSame([], $this->negotiator->takeEstablished());
	}

	public function testUnusableOfferIsRefusedBeforeATransportIsCreated() : void{
		try{
			$this->negotiator->beginNegotiation("v=0\r\n", "1234");
			self::fail("an offer with no media section was accepted");
		}catch(NegotiationException $e){
			self::assertSame(ErrorCode::FAILED_TO_SET_REMOTE_DESCRIPTION, $e->getErrorCode());
			self::assertSame([], $this->factory->created);
		}
	}

	public function testOfferWithoutIdentityIsRefusedWhenOneIsRequired() : void{
		$negotiator = $this->createNegotiator(allowAnonymous: false);

		try{
			$negotiator->beginNegotiation(SdpFixture::description(), "1234");
			self::fail("an anonymous offer was accepted");
		}catch(NegotiationException $e){
			self::assertSame(ErrorCode::IDENTITY_NOT_ALLOWED, $e->getErrorCode());
			self::assertSame([], $this->factory->created);
		}
	}

	public function testOfferWithTooManyCandidatesIsCutDownToTheLimit() : void{
		$this->negotiator->beginNegotiation(SdpFixture::description(array_fill(0, self::MAX_REMOTE_CANDIDATES + 1, SdpFixture::HOST_CANDIDATE)), "1234");

		$offer = $this->transport()->remoteOffer;
		self::assertNotNull($offer);
		self::assertSame(self::MAX_REMOTE_CANDIDATES, SessionDescription::parse($offer)->countCandidates());
	}

	public function testTransportThatCannotBeCreatedRefusesTheOffer() : void{
		$this->factory->fail = true;

		try{
			$this->negotiator->beginNegotiation(SdpFixture::description(), "1234");
			self::fail("the offer was accepted without a transport");
		}catch(NegotiationException $e){
			self::assertSame(ErrorCode::FAILED_TO_CREATE_PEER_CONNECTION, $e->getErrorCode());
		}
	}

	public function testTransportThatRejectsTheOfferIsClosed() : void{
		$this->factory->rejectOffers = true;

		try{
			$this->negotiator->beginNegotiation(SdpFixture::description(), "1234");
			self::fail("the offer was accepted although the transport rejected it");
		}catch(NegotiationException $e){
			self::assertSame(ErrorCode::FAILED_TO_SET_REMOTE_DESCRIPTION, $e->getErrorCode());
			self::assertTrue($this->transport()->closed);
		}
	}

	/**
	 * A peer behind NAT may only list addresses nobody else can reach. The address its offer actually came from
	 * is one the server knows works, so it is tried too.
	 */
	public function testAddressTheOfferCameFromIsTriedAsACandidate() : void{
		$this->negotiator->beginNegotiation(SdpFixture::description([SdpFixture::HOST_CANDIDATE]), "1234", peerAddress: "203.0.113.5");

		$candidates = $this->transport()->remoteCandidates;
		self::assertCount(1, $candidates);
		self::assertStringContainsString("203.0.113.5 51000 typ srflx", $candidates[0]);
	}

	public function testAddressTheOfferAlreadyNamesInAnotherSpellingIsNotAddedAgain() : void{
		$this->negotiator->beginNegotiation(
			SdpFixture::description(["candidate:1 1 UDP 2122317823 2001:db8:0:0:0:0:0:1 51000 typ host"]),
			"1234",
			peerAddress: "[2001:DB8::1]"
		);

		self::assertSame([], $this->transport()->remoteCandidates);
	}

	public function testTrickledCandidatesBeyondTheLimitAreIgnored() : void{
		$negotiation = $this->negotiator->beginNegotiation(SdpFixture::description(), "1234", CandidateMode::TRICKLE);

		for($i = 0; $i < self::MAX_REMOTE_CANDIDATES + 3; ++$i){
			$negotiation->addRemoteCandidate(SdpFixture::HOST_CANDIDATE);
		}

		self::assertCount(self::MAX_REMOTE_CANDIDATES, $this->transport()->remoteCandidates);
	}

	public function testCandidateTheTransportCannotUseIsReported() : void{
		$negotiation = $this->negotiator->beginNegotiation(SdpFixture::description(), "1234", CandidateMode::TRICKLE);
		$this->transport()->rejectCandidates = true;

		try{
			$negotiation->addRemoteCandidate("candidate:garbage");
			self::fail("an unusable candidate was accepted");
		}catch(NegotiationException $e){
			self::assertSame(ErrorCode::CANDIDATE_ADD, $e->getErrorCode());
			self::assertFalse($negotiation->isFinished(), "one bad candidate ended the whole negotiation");
		}
	}

	public function testShutdownFailsOngoingNegotiationsAndClosesWaitingPeers() : void{
		$ongoing = $this->negotiator->beginNegotiation(SdpFixture::description(), "1111");
		$ongoingTransport = $this->factory->created[0];

		$this->negotiator->beginNegotiation(SdpFixture::description(), "2222");
		$waitingTransport = $this->factory->created[1];
		$waitingTransport->gatheringComplete = true;
		$waitingTransport->localDescription = SdpFixture::description([SdpFixture::HOST_CANDIDATE]);
		$this->negotiator->tick(100.0);
		$this->openChannels($waitingTransport);
		$this->negotiator->tick(100.1);

		$this->negotiator->shutdown();

		self::assertSame(ErrorCode::NO_SIGNALING_CHANNEL, $ongoing->getFailureCode());
		self::assertTrue($ongoingTransport->closed);
		self::assertTrue($waitingTransport->closed, "an established peer nobody collected was left open");
		self::assertSame([], $this->negotiator->takeEstablished());
	}

	public function testOffersAreRefusedAfterShutdown() : void{
		$this->negotiator->shutdown();

		try{
			$this->negotiator->beginNegotiation(SdpFixture::description(), "1234");
			self::fail("an offer was accepted after shutdown");
		}catch(NegotiationException $e){
			self::assertSame(ErrorCode::NO_SIGNALING_CHANNEL, $e->getErrorCode());
		}
	}
}
