<?php
/**
 * Task which does the actual checking of updates
 *
 * Originally from https://github.com/XploreNet/silverstripe-composerupdates
 *
 * @author Matt Dwen
 * @license MIT
 */
class CheckComposerUpdatesTask extends BuildTask {
	/**
	 * @var string
	 */
	protected $title = 'Composer update checker';

	/**
	 * @var string
	 */
	protected $description = 'Checks if any composer dependencies can be updated.';

	/**
	 * Deserialized JSON from composer.lock
	 *
	 * @var object
	 */
	private $composerLock;

	/**
	 * Minimum required stability defined in the site composer.json
	 *
	 * @var string
	 */
	private $minimumStability;

	/**
	 * Whether or not to prefer stable packages
	 *
	 * @var bool
	 */
	private $preferStable;

	/**
	 * Known stability values
	 *
	 * @var array
	 */
	private $stabilityOptions = array(
		'dev',
		'alpha',
		'beta',
		'rc',
		'stable'
	);

	/**
	 * Whether to write all log messages or not
	 *
	 * @var bool
	 */
	private $extendedLogging = true;

	/**
	 * Retrieve an array of primary composer dependencies from composer.json
	 *
	 * @return array
	 */
	private function getPackages() {
		$composerPath = BASE_PATH . '/composer.json';
		if (!file_exists($composerPath)) {
			return null;
		}

		// Read the contents of composer.json
		$composerFile = file_get_contents($composerPath);

		// Parse the json
		$composerJson = json_decode($composerFile);

		ini_set('display_errors', 1);
		error_reporting(E_ALL);

		// Set the stability parameters
		$this->minimumStability = (isset($composerJson->{'minimum-stability'}))
			? $composerJson->{'minimum-stability'}
			: 'stable';

		$this->preferStable = (isset($composerJson->{'prefer-stable'}))
			? $composerJson->{'prefer-stable'}
			: true;

		$packages = array();
		foreach ($composerJson->require as $package => $version) {
			// Ensure there's a / in the name, probably not an addon with it
			if (!strpos($package, '/')) {
				continue;
			}

			$packages[] = $package;
		}

		return $packages;
	}

	/**
	 * Return an array of all Composer dependencies from composer.lock
	 *
	 * @return array(package => hash)
	 */
	private function getDependencies() {
		$composerPath = BASE_PATH . '/composer.lock';
		if (!file_exists($composerPath)) {
			return null;
		}

		// Read the contents of composer.json
		$composerFile = file_get_contents($composerPath);

		// Parse the json
		$dependencies = json_decode($composerFile);

		$packages = array();

		// Loop through the requirements
		foreach ($dependencies->packages as $package) {
			$packages[$package->name] = $package->version;
		}

		$this->composerLock = $dependencies;

		return $packages;
	}

	/**
	 * Check if an available version is better than the current version,
	 * considering stability requirements
	 *
	 * Returns FALSE if no update is available.
	 * Returns the best available version if an update is available.
	 *
	 * @param string $currentVersion
	 * @param string $availableVersions
	 * @return bool|string
	 */
	private function hasUpdate($currentVersion, $availableVersions) {
		$currentVersion = strtolower($currentVersion);

		// Check there are some versions
		if (count($availableVersions) < 1) {
			return false;
		}

		// If this is dev-master, compare the hashes
		if ($currentVersion === 'dev-master') {
			return $this->hasUpdateOnDevMaster($availableVersions);
		}

		// Loop through each available version
		$currentStability = $this->getStability($currentVersion);
		$bestVersion = $currentVersion;
		$bestStability = $currentStability;
		$availableVersions = array_reverse($availableVersions, true);
		foreach($availableVersions as $version => $details) {
			// Get the stability of the version
			$versionStability = $this->getStability($version);

			// Does this meet minimum stability
			if (!$this->isStableEnough($this->minimumStability, $versionStability)) {
				continue;
			}

			if ($this->preferStable) {
				// A simple php version compare rules out the dumb stuff
				if (version_compare($bestVersion, $version) !== -1) {
					continue;
				}
			} else {
				// We're doing a straight version compare
				$pureBestVersion = $this->getPureVersion($bestVersion);
				$pureVersion = $this->getPureVersion($version);

				// Checkout the version
				$continue = false;
				switch (version_compare($pureBestVersion, $pureVersion)) {
					case -1:
						// The version is better, take it
						break;

					case 0:
						// The version is the same.
						// Do another straight version compare to rule out rc1 vs rc2 etc...
						if ($bestStability == $versionStability) {
							if (version_compare($bestVersion, $version) !== -1) {
								$continue = true;
								break;
							}
						}
						break;

					case 1:
						// The version is worse, ignore it
						$continue = true;
						break;
				}

				if ($continue) {
					continue;
				}
			}

			$bestVersion = $version;
			$bestStability = $versionStability;
		}

		if ($bestVersion !== $currentVersion || $bestStability !== $currentStability) {
			if ($bestStability === 'stable') {
				return $bestVersion;
			}

			return $bestVersion . '-' . $bestStability;
		}

		return false;
	}

	/**
	 * Check the latest hash on the dev-master branch, and return it if different to the local hash
	 *
	 * FALSE is returned if the hash is the same.
	 *
	 * @param $availableVersions
	 * @return bool|string
	 */
	private function hasUpdateOnDevMaster($availableVersions) {
		// Get the dev-master version
		$devMaster = $availableVersions['dev-master'];

		// Sneak the name of the package
		$packageName = $devMaster->getName();

		// Get the local package details
		$localPackage = $this->getLocalPackage($packageName);

		// What's the current hash?
		$localHash = $localPackage->source->reference;

		// What's the latest hash in the available versions
		$remoteHash = $devMaster->getSource()->getReference();

		// return either the new hash or false
		return ($localHash != $remoteHash) ? $remoteHash : false;
	}

	/**
	 * Return details from composer.lock for a specific package
	 *
	 * @param string $packageName
	 * @return object
	 * @throws Exception if package cannot be found in composer.lock
	 */
	private function getLocalPackage($packageName) {
		foreach($this->composerLock->packages as $package) {
			if ($package->name == $packageName) {
				return $package;
			}
		}

		throw new Exception('Cannot locate local package ' . $packageName);
	}

	/**
	 * Retrieve the pure numerical version
	 *
	 * @param string $version
	 * @return string|null
	 */
	private function getPureVersion($version) {
		$matches = array();

		preg_match("/^(\d+\\.)?(\d+\\.)?(\\*|\d+)/", $version, $matches);

		if (count($matches) > 0) {
			return $matches[0];
		}

		return null;
	}

	/**
	 * Determine the stability of a given version
	 *
	 * @param string $version
	 * @return string
	 */
	private function getStability($version) {
		$version = strtolower($version);

		foreach($this->stabilityOptions as $option) {
			if (strpos($version, $option) !== false) {
				return $option;
			}
		}

		return 'stable';
	}

	/**
	 * Return a numerical representation of a stability
	 *
	 * Higher is more stable
	 *
	 * @param string $stability
	 * @return int
	 * @throws Exception If the stability is unknown
	 */
	private function getStabilityIndex($stability) {
		$stability = strtolower($stability);

		$index = array_search($stability, $this->stabilityOptions, true);

		if ($index === false) {
			throw new Exception("Unknown stability: $stability");
		}

		return $index;
	}

	/**
	 * Check if a stability meets a given minimum requirement
	 *
	 * @param $currentStability
	 * @param $possibleStability
	 * @return bool
	 */
	private function isStableEnough($currentStability, $possibleStability) {
		$minimumIndex = $this->getStabilityIndex($currentStability);
		$possibleIndex = $this->getStabilityIndex($possibleStability);

		return ($possibleIndex >= $minimumIndex);
	}

	/**
	 * Record package details in the database
	 *
	 * @param string $package Name of the Composer Package
	 * @param string $installed Currently installed version
	 * @param string|boolean $latest The latest available version
	 */
	private function recordUpdate($package, $installed, $latest) {
		// Is there a record already for the package? If so find it.
		$packages = ComposerUpdate::get()->filter(array('Name' => $package));

		// if there is already one use it otherwise create a new data object
		if ($packages->count() > 0) {
			$update = $packages->first();
		} else {
			$update = new ComposerUpdate();
			$update->Name = $package;
		}

		// If installed is dev-master get the hash
		if ($installed === 'dev-master') {
			$localPackage = $this->getLocalPackage($package);
			$installed = $localPackage->source->reference;
		}

		// Set the new details and save it
		$update->Installed = $installed;
		$update->Available = $latest;
		$update->write();
	}

	/**
	 * runs the actual steps to verify if there are updates available
	 *
	 * @param SS_HTTPRequest $request
	 */
	public function run($request) {
		// Retrieve the packages
		$packages = $this->getPackages();
		$dependencies = $this->getDependencies();

		// Load the Packagist API
		$packagist = new Packagist\Api\Client();

		// run through the packages and check each for updates
		foreach($packages as $package) {
			// verify that we need to check this package.
			if (!isset($dependencies[$package])) {
				continue;
			} else {
				// get information about this package from packagist.
				try {
					$latest = $packagist->get($package);
				} catch (Guzzle\Http\Exception\ClientErrorResponseException $e) {
					SS_Log::log($e->getMessage(), SS_Log::WARN);
					continue;
				}

				// Check if there is a newer version
				$currentVersion = $dependencies[$package];
				$result = $this->hasUpdate($currentVersion, $latest->getVersions());

				// Check if there is a newer version and if so record the update
				if ($result !== false) $this->recordUpdate($package, $currentVersion, $result);
			}
		}

		// finished message
		$this->message('The task finished running. You can find the updated information in the database now.');
	}

	/**
	 * prints a message during the run of the task
	 *
	 * @param string $text
	 */
	protected function message($text) {
		if (PHP_SAPI !== 'cli') $text = '<p>' . $text . '</p>' . PHP_EOL;

		echo $text . PHP_EOL;
	}
}
