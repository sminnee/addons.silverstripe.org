<?php

use Composer\Package\Package;
use Composer\Package\PackageInterface;

/**
 * Downloads an add-on and builds more details information about it.
 */
class AddonBuilder {

    const ADDONS_DIR = 'add-ons';

    const SCREENSHOTS_DIR = 'screenshots';

    private $packagist;

    public function __construct(PackagistService $packagist) {
        $this->packagist = $packagist;
    }

    public function build(Addon $addon) {
        $composer = $this->packagist->getComposer();
        $downloader = $composer->getDownloadManager();
        $packageVersions = $this->packagist->getPackageVersions($addon->Name);
        $time = time();

        if (!$packageVersions) {
            throw new Exception('Could not find corresponding Packagist versions');
        }

        // Get the latest local and packagist version pair.
        $version = $addon->Versions()->filter('Development', true)->first();
        foreach ($packageVersions as $packageVersion) {
            if ($packageVersion->getVersionNormalized() != $version->Version) {
                continue;
            }

            $path = implode('/', array(
                TEMP_FOLDER, self::ADDONS_DIR, $addon->Name
            ));

            // Convert PackagistAPI result into class compatible with Composer logic
            $package = new Package(
                $addon->Name,
                $packageVersion->getVersionNormalized(),
                $packageVersion->getVersion()
            );
            $package->setExtra($packageVersion->getExtra());
            if($source = $packageVersion->getSource()) {
                $package->setSourceUrl($source->getUrl());
                $package->setSourceType($source->getType());
                $package->setSourceReference($source->getReference());
            }
            if($dist = $packageVersion->getDist()) {
                $package->setDistUrl($dist->getUrl());
                $package->setDistType($dist->getType());
                $package->setDistReference($dist->getReference());
            }

            $this->download($package, $path);
            $this->buildReadme($addon, $path);
            $this->buildScreenshots($addon, $package, $path);
        }

        $addon->LastBuilt = $time;
        $addon->write();
    }

    protected function download(PackageInterface $package, $path) {
        $this->packagist
            ->getComposer()
            ->getDownloadManager()
            ->download($package, $path);
    }

    /**
     * Parses a readme file from markdown to HTML, then purifies it
     * @param Addon  $addon
     * @param string $path
     */
    protected function buildReadme(Addon $addon, $path)
    {
        $candidates = array(
            'README.md',
            'README.markdown',
            'README.mdown',
            'docs/en/index.md'
        );

        foreach ($candidates as $candidate) {
            $lower = strtolower($candidate);
            $paths = array("$path/$candidate", "$path/$lower");

            foreach ($paths as $path) {
                if (!file_exists($path)) {
                    return;
                }

                $parser = GitHubMarkdownService::create();
                if ($context = $this->getGitHubContext($addon)) {
                    $parser->setContext($context);
                }
                $readme = $parser->toHtml(file_get_contents($path));

                if (empty($readme)) {
                    return;
                }

                $readme = $parser->toHtml(file_get_contents($path));

                $purifier = new HTMLPurifier();
                $readme = $purifier->purify($readme, array(
                    'Cache.SerializerPath' => TEMP_FOLDER
                ));

                $readme = $this->replaceRelativeLinks($addon, $readme);
                $addon->Readme = $readme;
                return;
            }
        }
    }

    /**
     * Determine if the repository is from GitHub, and if so then return the "context" (vendor/module) from the path
     *
     * @param  Addon $addon
     * @return string|false
     */
    public function getGitHubContext(Addon $addon)
    {
        $repository = $addon->Repository;
        if (stripos($repository, '://github.com/') === false) {
            return false;
        }

        preg_match('/^http(?:s?):\/\/github\.com\/(?<module>.*)(\.git)?$/U', $repository, $matches);

        if (isset($matches['module'])) {
            return $matches['module'];
        }

        return false;
    }

    /**
     * Given an addon and a parsed HTML readme string, find and replace relative links with absolute
     * repository path links. This method applies to GitHub repositories only.
     *
     * @param  Addon  $addon
     * @param  string $readme
     * @return string
     */
    public function replaceRelativeLinks(Addon $addon, $readme)
    {
        if (!$this->hasGitHubRepository($addon)) {
            return $readme;
        }

        $dom = new DOMDocument;
        // LibXML needs a wrapper element...
        $dom->loadHTML('<div>' . $readme . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        $xpath = new DOMXPath($dom);

        // Select all anchors and images in the readme document
        $query = $xpath->query('//*[self::a or self::img]');

        foreach ($query as $element) { /** @var DOMElement $element */
            $attribute = ($element->nodeName === 'a') ? 'href' : 'src';
            $path = $element->getAttribute($attribute);
            if (!$this->isRelativeUri($path)) {
                continue;
            }

            // See GitHub readmes for example
            $folder = ($attribute === 'href') ? 'blob' : 'raw';
            $defaultBranch = 'master'; // Is this safe to assume?

            $element->setAttribute(
                $attribute,
                implode('/', array($addon->Repository, $folder, $defaultBranch, $path))
            );
        }

        // Return the inner HTML of the wrapper div... Reference: stackoverflow.com/a/39193507/2812842
        $node = $dom->getElementsByTagName('div')->item(0);
        return implode(array_map([$node->ownerDocument, 'saveHTML'], iterator_to_array($node->childNodes)));
    }

    /**
     * Decide whether a URI path is relative or not. The regex pattern matches prefixes that start with
     * a protocol, a slash or a hash. If they don't start with those things, then they are deemed to be
     * relative paths.
     *
     * @param  string $path
     * @return bool
     */
    public function isRelativeUri($path)
    {
        return !preg_match('/(^(?:https?:\/\/|\/|#).*$)/', $path);
    }

    /**
     * Determine whether an addon is hosted on GitHub
     *
     * @param  Addon $addon
     * @return bool
     */
    public function hasGitHubRepository(Addon $addon)
    {
        return (strpos($addon->Repository, 'github.com') !== false);
    }

    private function buildScreenshots(Addon $addon, PackageInterface $package, $path) {
        $extra = $package->getExtra();
        $screenshots = array();
        $target = self::SCREENSHOTS_DIR . '/' . $addon->Name;

        if (isset($extra['screenshots'])) {
            $screenshots = (array) $extra['screenshots'];
        } elseif (isset($extra['screenshot'])) {
            $screenshots = (array) $extra['screenshot'];
        }

        // Delete existing screenshots.
        foreach ($addon->Screenshots() as $screenshot) {
            $screenshot->delete();
        }

        $addon->Screenshots()->removeAll();

        foreach ($screenshots as $screenshot) {
            if (!is_string($screenshot)) {
                continue;
            }

            $scheme = parse_url($screenshot, PHP_URL_SCHEME);

            // Handle absolute image URLs.
            if ($scheme == 'http' || $scheme == 'https') {
                $temp = TEMP_FOLDER . '/' . md5($screenshot);

                if (!copy($screenshot, $temp)) {
                    continue;
                }

                $data = array(
                    'name' => basename($screenshot),
                    'size' => filesize($temp),
                    'tmp_name' => $temp,
                    'error' => 0
                );
            }
            // Handle images that are included in the repository.
            else {
                $source = $path . '/' . ltrim($screenshot, '/');

                // Prevent directory traversal.
                if ($source != realpath($source)) {
                    continue;
                }

                if (!file_exists($source)) {
                    continue;
                }

                $data = array(
                    'name' => basename($source),
                    'size' => filesize($source),
                    'tmp_name' => $source,
                    'error' => 0
                );
            }

            $upload = new Upload();
            $upload->setValidator(new AddonBuilderScreenshotValidator());
            $upload->load($data, $target);

            if($file = $upload->getFile()) {
                $addon->Screenshots()->add($file);
            }
        }
    }
}
