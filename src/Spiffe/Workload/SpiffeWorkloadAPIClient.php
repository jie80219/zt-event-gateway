<?php
// GENERATED CODE -- DO NOT EDIT!

// Original file comments:
// The following is the SPIFFE Workload API specification, represented as a
// Protocol Buffers v3 schema.
//
// For native types, please see:
// https://github.com/spiffe/spiffe/blob/main/standards/SPIFFE_Workload_API.md
//
namespace Spiffe\Workload;

/**
 * ////////////////////////////////////////////////////////////////////
 * Core RPCs
 * ////////////////////////////////////////////////////////////////////
 *
 */
class SpiffeWorkloadAPIClient extends \Grpc\BaseStub {

    /**
     * @param string $hostname hostname
     * @param array $opts channel options
     * @param \Grpc\Channel $channel (optional) re-use channel object
     */
    public function __construct($hostname, $opts, $channel = null) {
        parent::__construct($hostname, $opts, $channel);
    }

    /**
     * Fetch X.509-SVIDs for all SPIFFE identities the workload is entitled to,
     * as well as related information like trust bundles and CRLs. As this
     * information changes, subsequent messages will be streamed from the
     * server.
     * @param \Spiffe\Workload\X509SVIDRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\ServerStreamingCall
     */
    public function FetchX509SVID(\Spiffe\Workload\X509SVIDRequest $argument,
      $metadata = [], $options = []) {
        return $this->_serverStreamRequest('/spiffe.workload.SpiffeWorkloadAPI/FetchX509SVID',
        $argument,
        ['\Spiffe\Workload\X509SVIDResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Fetch trust bundles and CRLs. Useful for clients that only need to
     * validate SVIDs without obtaining an SVID for themself. As this
     * information changes, subsequent messages will be streamed from the
     * server.
     * @param \Spiffe\Workload\X509BundlesRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\ServerStreamingCall
     */
    public function FetchX509Bundles(\Spiffe\Workload\X509BundlesRequest $argument,
      $metadata = [], $options = []) {
        return $this->_serverStreamRequest('/spiffe.workload.SpiffeWorkloadAPI/FetchX509Bundles',
        $argument,
        ['\Spiffe\Workload\X509BundlesResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * /////////////////////////////////////////////////////////////////////
     * JWT-SVID Profile
     * /////////////////////////////////////////////////////////////////////
     *
     * Fetch JWT-SVIDs for all SPIFFE identities the workload is entitled to,
     * for the requested audience. If an optional SPIFFE ID is requested, only
     * the JWT-SVID for that SPIFFE ID is returned.
     * @param \Spiffe\Workload\JWTSVIDRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function FetchJWTSVID(\Spiffe\Workload\JWTSVIDRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/spiffe.workload.SpiffeWorkloadAPI/FetchJWTSVID',
        $argument,
        ['\Spiffe\Workload\JWTSVIDResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Fetches the JWT bundles, formatted as JWKS documents, keyed by the
     * SPIFFE ID of the trust domain. As this information changes, subsequent
     * messages will be streamed from the server.
     * @param \Spiffe\Workload\JWTBundlesRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\ServerStreamingCall
     */
    public function FetchJWTBundles(\Spiffe\Workload\JWTBundlesRequest $argument,
      $metadata = [], $options = []) {
        return $this->_serverStreamRequest('/spiffe.workload.SpiffeWorkloadAPI/FetchJWTBundles',
        $argument,
        ['\Spiffe\Workload\JWTBundlesResponse', 'decode'],
        $metadata, $options);
    }

    /**
     * Validates a JWT-SVID against the requested audience. Returns the SPIFFE
     * ID of the JWT-SVID and JWT claims.
     * @param \Spiffe\Workload\ValidateJWTSVIDRequest $argument input argument
     * @param array $metadata metadata
     * @param array $options call options
     * @return \Grpc\UnaryCall
     */
    public function ValidateJWTSVID(\Spiffe\Workload\ValidateJWTSVIDRequest $argument,
      $metadata = [], $options = []) {
        return $this->_simpleRequest('/spiffe.workload.SpiffeWorkloadAPI/ValidateJWTSVID',
        $argument,
        ['\Spiffe\Workload\ValidateJWTSVIDResponse', 'decode'],
        $metadata, $options);
    }

}
