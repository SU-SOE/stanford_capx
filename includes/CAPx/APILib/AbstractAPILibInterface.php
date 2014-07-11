<?php
/**
 * @file
 * HTTP Client Interface.
 * Ensures that all abstract clients use Guzzle
 */

namespace CAPx\APILib;

use \Guzzle\Http\Client as GuzzleClient;

/**
 * Api interface
 *
 */
interface AbstractAPILibInterface {

  public function __construct(GuzzleClient $client);

}
