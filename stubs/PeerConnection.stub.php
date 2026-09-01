<?php

/**
 * @generate-class-entries
 * @generate-legacy-arginfo 80200
 */

namespace pmmp\webrtc;

/**
 * A single WebRTC peer connection.
 */
final class PeerConnection
{
    public function __construct(PeerConnectionOptions $options) {}

    /**
     * Apply a remote offer and begin producing an answer.
     *
     * Unknown session-level attributes in the SDP are ignored, so the offer
     * can be passed through unmodified.
     *
     * @throws WebRtcException if the SDP cannot be parsed
     */
    public function setRemoteOffer(string $sdp): void {}

    /**
     * Apply a remote answer to an offer this connection produced.
     *
     * @throws WebRtcException if the SDP cannot be parsed
     */
    public function setRemoteAnswer(string $sdp): void {}

    /**
     * Open a channel and start negotiation, making this connection the offerer.
     *
     * The local description becomes available once ICE gathering completes.
     */
    public function createDataChannel(string $label, ?DataChannelOptions $options = null): DataChannel {}

    /**
     * Take the channels opened by the peer since the last call.
     *
     * @return DataChannel[]
     */
    public function pollDataChannels(): array {}

    /**
     * Take the local candidates gathered since the last call.
     *
     * Only needed for trickle ICE. Without it the local description carries
     * every candidate once getGatheringState() returns COMPLETE, which is also
     * the signal that no further candidate will appear here.
     *
     * @return IceCandidate[]
     */
    public function pollLocalCandidates(): array {}

    /**
     * Add a candidate the peer sent over signaling.
     *
     * A remote description has to be in place first, so a candidate that
     * arrives before the offer or answer must be held by the caller until it
     * has been applied.
     *
     * @throws WebRtcException if there is no remote description yet, or if the
     *                         candidate cannot be used
     */
    public function addRemoteCandidate(IceCandidate $candidate): void {}

    public function getGatheringState(): GatheringState {}

    public function getState(): ConnectionState {}

    /**
     * The local SDP, or null if none has been produced yet.
     *
     * With non-trickle ICE this is only complete once getGatheringState()
     * returns GatheringState::COMPLETE.
     */
    public function getLocalDescription(): ?string {}

    /**
     * Hex digest of the local DTLS certificate, formatted as "AB:CD:...".
     * Null until a local description exists.
     */
    public function getLocalFingerprint(): ?string {}

    /**
     * Local end of the selected candidate pair, e.g. "192.0.2.10:54321".
     * Null until ICE has selected a pair.
     */
    public function getLocalAddress(): ?string {}

    /**
     * Remote end of the selected candidate pair.
     * Null until ICE has selected a pair.
     */
    public function getRemoteAddress(): ?string {}

    /**
     * Close the connection and release the underlying resources immediately.
     * Any further method call throws WebRtcException.
     */
    public function close(): void {}
}
