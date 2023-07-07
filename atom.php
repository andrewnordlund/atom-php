<?php
// I used https://datatracker.ietf.org/doc/html/rfc4287 to help build this

// Oh boy....
// When you do $atom->getTitle(), should it return the object?  Or the string?

class atomFeed {
	private $filename = null;	// Filename to save....but not necessarily to load?  What if you wanted to load something remotely?
	private $xmlDoc = null;
	private $rootNode = null;
	private $rootNodeAttributes = array();
	private $ns = array("xmlns"=>"http://www.w3.org/2005/Atom");
	private $categories = array();
	private $generator = null;
	private $icon=null;
	private $logo=null;
	private $rights = null;
	private $id = null;
	private $title = null;
	private $subtitle = null;
	private $updated = null;
	private $author = array();
	private $contributor = array();
	private $links = array();
	private $entries = array();	// Thought:  if this were an associative array, then updating would be easier.
	private $extensions = array();	// this will just be an array of nodes
	private $base = null;
	private $lang = null;
	private $logging = false;
	private $doIt = true;
	private $version = "0.1.0-b1";
	
	static function getMetas() {
		return array("author", "category", "contributor", "generator", "icon", "id", "link", "logo", "rights", "subtitle", "title", "updated");	// Future version:  Add Extension stuff
	} // End of getMetas

	public function __construct($logging=false, $doIt=true) {
		$this->setLogging($logging);
		$this->setDoIt($doIt);

	} // End of __construct

	function init () {
		$this->setGenerator ("Nordburg ATOM Feed Generator", "https://www.nordburg.ca/lib/atom.php", $this->getVersion());
		$this->setUpdated($this->getAtomTime());
	} // End of init

	function load ($loc=null, $gatherEntries=true) {
		// First thing you should do is get the XML file as a DOMDocument.  And then parse.
		$rv = true;
		$this->xml = new DOMDocument ();

		if ($loc && is_string($loc)) {
			if (preg_match("/^<(feed|source) /", $loc)) $loc = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n$loc";

			if (preg_match("/^<\?xml([ \s]|\?>)/", $loc)) {
				// It's a string.  Load it as such.
				$this->xml->loadXML($loc);
			} elseif (preg_match("/^https?:\/\//i", $loc)) {
				// It's a web address.  Fetch it as such
				// From https://www.php.net/manual/en/domdocument.load.php and https://www.php.net/manual/en/function.libxml-set-streams-context.php and https://www.php.net/manual/en/context.http.php
				$opts = array('http' => array('user_agent' => 'PHP libxml agent'));

				$context = stream_context_create($opts);
				libxml_set_streams_context($context);

				$this->xml->load($loc);
			} else {
				$loc = atomFeed::getAbsolute($loc);
				// It's not an XML string.  Must be a filename.  If the filename is unset, might as well use this
				if ($this->filename == null || $this->filename == "") $this->setFilename($loc);
				if (file_exists($loc)) {
					$this->xml->load($loc);
				} else {
					// I don't know what to do here.  Throw an exception?
					$rv = false;
					throw new RuntimeException("Tried to load file that does not exist");
				}
			}
		} else {
			if (file_exists($this->getFilename())) {
				$this->xml->load($this->getFilename());
			} else {
				// I don't know what to do here.  Throw an exception?
				$rv = false;
				throw new RuntimeException("Tried to load file that does not exist");
			}
		}


		// Okay, by now the file should be loaded.  Proced with converting to atomFeed

		if ($rv) {
			$rootNode = $this->xml->getElementsByTagName("feed");
			if ($rootNode) {
				// It's already there.
				$rootNode = $rootNode->item(0);
				if ($rootNode->nodeName == "feed") {
					// Get the rootnode and gather as many attributes and namespaces as possible.
					// Gotta do this manually cuz DOMDocument doesn't like a node to have >1 namespace.
					$rnStr = $this->xml->saveXML($rootNode);
					preg_match("/^<([^> \/]*)((.|\n)*?)>/", $rnStr, $stuff);

					preg_match_all("/[ \s\t\n\r\f]+(\S*?)\s*=\s*('[^']*'|\"[^\"]*\"|\S+)/", $stuff[0], $attribs);
					for ($i= 0; $i<count($attribs[1]);$i++) {
						$v = preg_replace ("/^([\"'])(.*)\\1$/", "$2", $attribs[2][$i]);
						if (strpos($attribs[1][$i], "xmlns") === 0) {
							$this->addNS($attribs[1][$i], $v);
						} else {
							$this->addRootNodeAttribute ($attribs[1][$i], $v);
						}
					}
						
					foreach ($rootNode->childNodes as $node) {
						if ($node->nodeType == XML_ELEMENT_NODE) {
							$base = ($node->hasAttribute("xml:base") ? $node->getAttribute("xml:base") : null);
							$lang = ($node->hasAttribute("xml:lang") ? $node->getAttribute("xml:lang") : null);
							switch ($node->nodeName) {
							case "category":
								$t = $node->getAttribute("term");
								$s = ($node->hasAttribute("scheme") ? $node->getAttribute("scheme") : null);
								$lbl = ($node->hasAttribute("label") ? $node->getAttribute("label") : null);
								$this->addCategory($t, $s, $lbl, $base, $lang);
								break;
							case "generator":
								$u = ($node->hasAttribute("uri") ? $node->getAttribute("uri") : null);
								$v = ($node->hasAttribute("version") ? $node->getAttribute("version") : null);
								$g = $node->nodeValue;
								$this->setGenerator($g, $u, $v, $base, $lang);
								break;
							case "icon":
								$this->setIcon($node->nodeValue, $base, $lang);
								break;
							case "logo":
								$this->setLogo($node->nodeValue, $base, $lang);
								break;
							case "rights":
								$this->setRights($node->nodeValue, $base, $lang);
								break;
							case "id":
								$this->setID($node->nodeValue, $base, $lang);
								break;
							case "title":
								$type = ($node->hasAttribute("type") ? $node->getAttribute("type") : null);
								$this->setTitle($node->nodeValue, $type, $base, $lang);
								break;
							case "subtitle":
								$type = ($node->hasAttribute("type") ? $node->getAttribute("type") : null);
								$this->setSubtitle($node->nodeValue, $type, $base, $lang);
								break;
							case "updated":
								$this->setUpdated($node->nodeValue, $base, $lang);
								break;
							case "author":
								$nm = $node->getElementsByTagName("name");
								$nm = (count($nm)>0 ? $nm[0]->nodeValue : null);
								$em = $node->getElementsByTagName("email");
								$em = (count($em)>0 ? $em[0]->nodeValue : null);
								$uri = $node->getElementsByTagName("uri");
								$uri = (count($uri)>0 ? $uri[0]->nodeValue : null);
								$this->addAuthor($nm, $em, $uri, $base, $lang);
								break;
							case "contributor":
								$nm = $node->getElementsByTagName("name");
								$nm = (count($nm)>0 ? $nm[0]->nodeValue : null);
								$em = $node->getElementsByTagName("email");
								$em = (count($em)>0 ? $em[0]->nodeValue : null);
								$uri = $node->getElementsByTagName("uri");
								$uri = (count($uri)>0 ? $uri[0]->nodeValue : null);
								$this->addContributor($nm, $em, $uri, $base, $lang);
								break;
							case "link":
								$rel = ($node->hasAttribute("rel") ? $node->getAttribute("rel") : null);
								$title = ($node->hasAttribute("title") ? $node->getAttribute("title") : null);
								$hreflang = ($node->hasAttribute("hreflang") ? $node->getAttribute("hreflang") : null);
								$type = ($node->hasAttribute("type") ? $node->getAttribute("type") : null);
								$length = ($node->hasAttribute("length") ? $node->getAttribute("length") : null);
								$this->addLink($node->getAttribute("href"), $rel, $title, $hreflang, $type, $length, $base, $lang);
								break;
							case "entry":
								if ($gatherEntries) $this->addEntry($node);
								break;
							default:
								//Do stuff assuming this is an extension thingy.
								$this->addExtension($node);
							}
						} // end of if $node->nodeType == XML_ELEMENT_NODE
					} // End of foreach
				} else {
					$rv = "The rootnode is not a feed.";
					if ($this->logging) echo "<p class=\"nordburgLogging\">$rv</p>\n";
					throw new RuntimeException($rv);
				}
			} else {
				$rv = "No rootnode.";
				if ($this->logging) echo "<p class=\"nordburgLogging\">$rv</p>\n";
				throw new RuntimeException($rv);
			}
		} else {
			// $rv must be false to get here.


			// It's not there.  Create it.
			// Or maybe not.  Maybe only create it on save?
			// But there should be a way to say "Hey, you're trying to load
			// Something that doesn't exist, so you now have to either change the
			// filename to something that does exist, or fill in the required
			// metadata so that it can exist.
			/*
			$xml = new xmlParser('<?xml version="1.0" encoding="utf-8"><feed/>');
			$rootNode = new $this->xml->getRootNode();
			$rootNode->setAttribute("xmlns", $this->getNS());
			$this->setXML($xml);
			*/

		}
		return $rv;
	} // End of load

	function setFilename ($fn) {
		$this->filename = atomFeed::getAbsolute($fn);
	} // End of setFilename
	function getFilename () {return $this->filename;}

	function addNS ($attr, $ns) {
		$this->ns[$attr] = $ns;
		//$this->rootNode()->setAttribute("xmlns", $this->ns);
	} // End of setNS
	function getNSs () {return $this->ns;}
	function getNS ($attr) { return (array_key_exists($attr, $this->ns) ? $this->ns[$attr] : null); }

	function addRootNodeAttribute ($attr, $val) {
		$this->rootNodeAttributes[$attr] = $val;
	} // End of addRootNodeAttribute
	function getRootNodeAttributes () {return $this->rootNodeAttributes;}
	function getRootNodeAttribute($attr) {return (array_key_exists($attr, $this->rootNodeAttributes) ? $this->rootNodeAttributes[$attr] : null);}

	function setID ($id, $b=null, $l=null) {
		$this->id = new atomElement($id, $b, $l);
		/*
		$theNode = $this->rootNode->getElementsByTagName("id");
		if (count($theNode) > 0) {
			$theNode = $theNode[0];
			$theNode->setTextNode($id);
		} else {
			$theNode = getXML()->createNode("id");
			$theNode->setTextNode($id);
			$this->rootNode->appendChild($theNode);
		}
		*/

	} // End of setID
	function getID () {return $this->id->getContent();}
	function getIDObj () { return $this->id; }

	function setTitle ($c, $t="text", $b=null, $l=null) {
		$this->title = new atomContent($c, $t, $b, $l);
	} // End of setTitle
	function getTitle () {return $this->title->getContent();}
	function getTitleObj () {return $this->title;}

	function setSubtitle ($c, $t="text", $b=null, $l=null) {
		$this->subtitle = new atomContent($c, $t, $b, $l);
	} // End of setSubtitle
	function getSubtitle () {return $this->subtitle->getContent();}
	function getSubtitleObj () { return $this->subtitle;}

	function setUpdated ($t, $b=null, $l=null) {
		$this->updated = new atomDate($t, $b, $l);
	} // End of setUpdated
	function getUpdated () {return $this->updated->getContent();}
	function getUpdatedObj () {return $this->updated;}

	function setGenerator ($name=null, $uri=null, $version=null, $base=null, $lang=null) {
		$this->generator = new atomGenerator($name, $uri, $version, $base, $lang);
	} // End of setGenerator
	function getGenerator () {return $this->generator;}

	function addCategory ($t, $s=null, $lbl=null, $b, $l) {
		$t = trim($t);
		if (preg_match("/^\w+$/", $t)) {
			$cat = new atomCategory($t, $s, $l, $b, $l);
			$this->categories[$t] = $cat;
		}
	} // End of addCategory
	function getCategories () { return $this->categories; }

	function addAuthor ($n, $e=null, $i=null, $b=null, $l=null) {
		array_push($this->author, new atomPerson($n, $e, $i));
	} // End of addAuthor
	function getAuthors() { return $this->author;}

	function addContributor ($n, $e=null, $i=null, $b=null, $l=null) {
		$n = trim($n);
		$isThere = false;
		$contributors = $this->getContributors();
		for ($i = 0; $i<count($contributors) && !$isThere; $i++) {
			$pattern = "/^$n$/i";
			if (preg_match($pattern, $contributors[$i]->getName())) $isThere=true;
		}
		if (!$isThere) array_push($this->contributors, new atomPerson($n, $e, $i, $b, $l));
	} // End of addContributor
	function getContributors() { return $this->contributors; }

	function addEntry ($e) {
		$eid = null;
		if ($e instanceof atomEntry) {
			$eid = $e->getID();
		} elseif ($e instanceof DOMElement) {
			if ($e->nodeType == XML_ELEMENT_NODE && $e->nodeName == "entry") {
				$e = new atomEntry($e);
				$eid = $e->getID();
			}
		}
		if ($eid) $this->entries[$eid] = $e; // Should this be associative, or not?
	} // End of addEntry
	function getEntries () {return $this->entries;}
	function clearEntries () { 
		unset($this->entries);
		$this->entries = array();
	} // End of clearEntries
	
	function setIcon ($i, $b=null, $l=null) {
		$this->icon = new atomElement($i, $b, $l);
	} // End of setIcon
	function getIcon () {return $this->icon->getContent();}
	function getIconObj () {return $this->icon;}

	function setLogo ($i, $b=null, $l=null) {
		$this->logo = new atomElement($i, $b, $l);
	} // End of setKLogo
	function getLogo () {return $this->logo->getContent();}
	function getLogoObj () {return $this->logo;}

	function setRights ($i, $b=null,$l=null) {
		$this->rights = new atomElement($i, $b, $l);
	} // End of setRights
	function getRights () {return $this->rights->getContent();}
	function getRightsObj () {return $this->rights;}

	function addLink($h=null, $r=null, $title=null, $hl=null, $type=null, $len=null, $b=null, $l=null) {
		if ($h) {
			array_push($this->links, new atomLink($h, $r, $title, $hl, $type, $len, $b, $l));
		}
	} // End of addLink
	function getLinks() { return $this->links;}


	/*function setXML ($xml) {
		if ($xml instanceof xmlParser) {
			$this->xml = $xml;
		} else {
			if ($this->getLogging()) echo "<p class=\"nordburgLogging\">Couldn't set the xml file because it's not a real xml instance.</p>\n";
		}
	} // End of setXML
	function getXML () {return $this->xml;}
	*/
	/*
	function setRootNode($rn) {
		if ($rn instanceof xmlNode) {
			$this->rootNode = $rn;
		} else {
			if ($this->getLogging()) echo "<p class=\"nordburgLogging\">Couldn't set rootnode $rn cuz it's not an xmlNode.</p>\n";
		}
	} // End of setRootNode
	function getRootNode() {return $this->rootNode;}
	*/

	function setLogging($l) {
		if (is_bool($l)) {
			$this->logging = $l;
		}
	} // End of setLogging
	function getLogging(){ return $this->logging; }

	function setDoIt($di) {
		if (is_bool($di)) {
			$this->doIt = $di;
		}
	} // End of setDoIt
	function getDoIt() { return $this->doIt;}

	private static function setCommonAttributes ($node, $prop) {
		$base = $prop->getBase();
		$lang = $prop->getLang();
		if ($base) $node->setAttribute("xml:base", $base);
		if ($lang) $node->setAttribute("xml:lang", $lang);
	} // End of setCommonAttributes

	function save() {
		$rv = true;
		if ($this->getFilename()) {
			$this->feedToXML();
			if ($this->doIt) {
				if ($this->logging) echo "Gonna actually save now to " . $this->getFilename() . ".<br>\n";
				$this->xmlDoc->save($this->getFilename());
				// Gotta figure out how to save.
			} else {
				if ($this->logging) echo "Would save to " . $this->getFilename() . ", but \$doIt is " . $this->doIt . ".<br>\n";
			}

		} else {
			if ($this->getLogging()) echo "<p class=\"nordburgLogging\">atom::save::couldn't save because there's no filename associated.</p>";
			$rv = false;
			throw new RuntimeException("No filename");
		}

	} // End of save
	function feedToXML ($doEntries=true, $rn="feed") {
		// First generate the XML
		$rv = true;
		$this->xmlDoc = new DOMDocument();

		$rootNode = $this->xmlDoc->createElement($rn);
		foreach ($this->ns as $nsname=>$url) {
			$rootNode->setAttribute($nsname, $url);
		}
		foreach ($this->rootNodeAttributes as $nm=>$vl) {
			$rootNode->setAttribute($nm, $vl);
		}
		// ID
		if ($this->id) {
			$idNode = $this->xmlDoc->createElement("id", $this->getID());
			$this->setCommonAttributes($idNode, $this->getIDObj());
			$rootNode->appendChild($idNode);
		} else {
			$rv = false;
		}

		// Title
		if ($this->title) {
			$title = $this->getTitle();
			$titleNode = $this->xmlDoc->createElement("title", $this->getTitle());
			$this->setCommonAttributes($titleNode, $this->title);
			$rootNode->appendChild($titleNode);
		} else {
			$rv = false;
		}

		// Subtitle
		if ($this->subtitle) {
			$subtitleNode = $this->xmlDoc->createElement("subtitle", $this->getSubtitle());
			$this->setCommonAttributes($subtitleNode, $this->subtitle);
			$rootNode->appendChild($subtitleNode);
		}
			
		// Author
		for ($i = 0; $i<count($this->author); $i++) {
			$authNode = $this->xmlDoc->createElement("author");
			$name = $this->author[$i]->getName();
			$email = $this->author[$i]->getEmail();
			$uri = $this->author[$i]->getURI();
			$base = $this->author[$i]->getBase();
			$lang = $this->author[$i]->getLang();
			if ($name) {
				$nameNode = $this->xmlDoc->createElement("name", $name);
				$authNode->appendChild($nameNode);
			}
			if ($uri) {
				$uriNode = $this->xmlDoc->createElement("uri", $uri);
				$authNode->appendChild($uriNode);
			}
			if ($email) {
				$emailNode = $this->xmlDoc->createElement("email", $email);
				$authNode->appendChild($emailNode);
			}
			if ($base) $authNode->setAttribute("xml:base", $base);
			if ($lang) $authNode->setAttribute("xml:lang", $lang);
			$rootNode->appendChild($authNode);
		}
			
		// Categories
		for ($i = 0; $i < count($this->categories); $i++) {
			$catNode = $this->xmlDoc->createElement("category");
			$catNode->setAttribute("term", $this->categories[$i]->getTerm());
			//$this->setCommonAttributes($catNode, $this->getID());
			$scheme = $this->categories[$i]->getScheme();
			$label = $this->categories[$i]->getLabel();
			$base = $this->categories[$i]->getBase();
			$lang = $this->categories[$i]->getLang();
			
			if ($scheme) $catNode->setAttribute("scheme", $scheme);
			if ($label) $catNode->setAttribute("label", $label);
			if ($base) $catNode->setAttribute("xml:base", $base);
			if ($lang) $catNode->setAttribute("xml:lang", $lang);
			$rootNode->appendChild($catNode);
		}

		// Contributors
		for ($i = 0; $i < count($this->contributor); $i++) {
			$contribNode = $this->xmlDoc->createElement("contributor");
			$name = $this->contributor[$i]->getName();
			$email = $this->contributor[$i]->getEmail();
			$uri = $this->contributor[$i]->getURI();
			$base = $this->contributor[$i]->getBase();
			$lang = $this->contributor[$i]->getLang();
			if ($name) {
				$nameNode = $this->xmlDoc->createElement("name", $name);
				$contribNode->appendChild($nameNode);
			}
			if ($uri) {
				$uriNode = $this->xmlDoc->createElement("uri", $uri);
				$contribNode->appendChild($uriNode);
			}
			if ($email) {
				$emailNode = $this->xmlDoc->createElement("email", $email);
				$contribNode->appendChild($emailNode);
			}
			if ($base) $contribNode->setAttribute("xml:base", $base);
			if ($lang) $contribNode->setAttribute("xml:lang", $lang);
	
			$rootNode->appendChild($contribNode);
		}
			

		// Generator
		if ($this->generator) {
			$generator = $this->getGenerator();
			$genNode = $this->xmlDoc->createElement("generator", $generator->getContent());
			$genUri = $generator->getURI();
			$genVer = $generator->getVersion();
			$genBase = $generator->getBase();
			$genLang = $generator->getLang();
			if ($genUri) $genNode->setAttribute("uri", $genUri);
			if ($genVer) $genNode->setAttribute("version", $genVer);
			if ($genBase) $genNode->setAttribute("xml:base", $genBase);
			if ($genLang) $genNode->setAttribute("xml:lang", $genLang);
		
			$rootNode->appendChild($genNode);
		}

		// Icon
		if ($this->icon) {
			$iconNode = $this->xmlDoc->createElement("icon", $this->icon->getContent());
			$this->setCommonAttributes($iconNode, $this->icon);
			$rootNode->appendChild($iconNode);
		}

		// Links
		for ($i = 0; $i < count($this->links); $i++) {
			$linkNode = $this->xmlDoc->createElement("link");
			
			$rel = $this->links[$i]->getRel();
			$type = $this->links[$i]->getType();
			$hreflang = $this->links[$i]->getHreflang();
			$title = $this->links[$i]->getTitle();
			$length = $this->links[$i]->getLength();
			$base = $this->links[$i]->getBase();
			$lang = $this->links[$i]->getLang();
				

			$linkNode->setAttribute("href", $this->links[$i]->getHref());
			if ($rel) $linkNode->setAttribute("rel", $rel);
			if ($type) $linkNode->setAttribute("type", $type);
			if ($hreflang) $linkNode->setAttribute("hreflang", $hreflang);
			if ($title) $linkNode->setAttribute("title", $title);
			if ($length) $linkNode->setAttribute("length", $length);
			if ($base) $linkNode->setAttribute("xml:base", $base);
			if ($lang) $linkNode->setAttribute("xml:lang", $lang);

			$rootNode->appendChild($linkNode);
		}
			

		// Logo
		if ($this->logo) {
			$logoNode = $this->xmlDoc->createElement("logo", $this->getLogo());
			$this->setCommonAttributes($logoNode, $this->getLogoObj());
			$rootNode->appendChild($logoNode);
		}

		// Rights node
		if ($this->rights) {
			$rightsNode = $this->xmlDoc->createElement("rights", $this->getRights());
			$this->setCommonAttributes($rightsNode, $this->getRightsObj());
			$rootNode->appendChild($rightsNode);
		}

		// Updated
		if ($this->updated) {
			$updatedNode = $this->xmlDoc->createElement("updated", $this->getUpdated());
			$this->setCommonAttributes($updatedNode, $this->getUpdatedObj());
			$rootNode->appendChild($updatedNode);
		}
		// Extensions
		for ($i = 0; $i<count($this->extensions); $i++) {
			if ($this->extensions[$i] instanceof DOMElement) $rootNode->appendChild($this->extensions[$i]);
		}

		// Now add Entries....
		if ($doEntries) {
			foreach ($this->entries as $id=>$entry) {
				// Get a node from atomEntry
				$entryNode = $entry->createNode($this->xmlDoc);
				if ($entryNode) $rootNode->appendChild($entryNode);
			}
		}

		$this->xmlDoc->appendChild($rootNode);
		/*
		$xml = $this->getXML();
		$this->xml->setFilename($this->getFilename());
		if ($this->logging) echo "Saving to " . $this->getFilename() . "<br>\n";
		if ($this->doIt) $rv = $this->xml->save();
		*/
		if ($this->logging) {
			$htmlFriendly = str_replace("<", "&lt;", $this->xmlDoc->saveXML());
			$htmlFriendly = preg_replace("/&lt;([^\/])/", "<br>&lt;$1", $htmlFriendly);
			$htmlFriendly = preg_replace("/(\&lt;\/[^>]+>)$/", "<br>\n$1<br>\n", $htmlFriendly);
			echo $htmlFriendly . "<br>\n"; //str_replace("><", "><br>&lt;", $this->xmlDoc->saveXML());
		}
		//echo "feedToXML::Done changing feedToXML.  Returning $rv.<br>\n";
		return $rv;
	} // end of feedToXML

	function addExtension ($ex) {
		array_push($this->extensions, $ex); //$this->xmlDoc->saveXML($ex));
	} // End of addExtension
	function getExtensions () { return $this->extensions; }

	function setBase ($b) {
		$this->base = $b;
	} // End of setBase
	function getBase () { return $this->base; }

	function setLang ($l) {
		if (preg_match("/^[a-z]{2,3}(-[A-Z]{2,3})?$/", $l)) $this->lang = $l;
	} // End of setLang
	function getLang () { return $this->lang; }

	function getVersion () { return $this->version; }

	public static function getAtomTime () {
		date_default_timezone_set('UTC');
		$today = date("Y-m-d") . "T" . date("H:i:s") . "Z";
		return $today;
	} // End of getDateTime

	public static function getAbsolute(string $path): string {
		// Stolen from https://www.php.net/manual/en/function.realpath.php
		// Cleaning path regarding OS
		$path = mb_ereg_replace('\\\\|/', DIRECTORY_SEPARATOR, $path, 'msr');
		// Check if path start with a separator (UNIX)
		$startWithSeparator = $path[0] === DIRECTORY_SEPARATOR;
		// Check if start with drive letter
		preg_match('/^[a-z]:/', $path, $matches);
		$startWithLetterDir = isset($matches[0]) ? $matches[0] : false;
		// Get and filter empty sub paths
		$subPaths = array_filter(explode(DIRECTORY_SEPARATOR, $path), 'mb_strlen');

		$absolutes = [];
		foreach ($subPaths as $subPath) {
			if ($subPath === ".") continue;
		
			// if $startWithSeparator is false
			// and $startWithLetterDir
			// and (absolutes is empty or all previous values are ..)
			// save absolute cause that's a relative and we can't deal with that and just forget that we want go up
			if ($subPath === ".." && !$startWithSeparator && !$startWithLetterDir && empty(array_filter($absolutes, function ($value) {return !($value === "..");}))) {
				$absolutes[] = $subPath;
				continue;
			}
		    	if ($subPath === "..") {
				array_pop($absolutes);
				continue;
			}
			$absolutes[] = $subPath;
		}

		return (($startWithSeparator ? DIRECTORY_SEPARATOR : $startWithLetterDir) ? $startWithLetterDir.DIRECTORY_SEPARATOR : '') . implode(DIRECTORY_SEPARATOR, $absolutes);

	} // End of getAbsolute


}// End of class atomFeed

class atomEntry {
	private $id = null;
	private $author = array();
	private $categories = array();
	private $content = null;	// If non-existant, you need a link[rel=alternate]. If has src, then must use source (below)
	private $contributor = array();
	private $title = null;
	private $links = array();
	private $published = null;	// Date
	private $rights = null;
	private $summary = null;	// Must be here if content has a src, or the entry contains content that is base64 encoded
	private $source = null;		// atomFeed attributes (minus entries, of course)
	private $updated = null;
	private $extensions = array();
	private $base = null;
	private $lang = null;
	private $xmlDoc = null;
	private $logging = false;
	private $doIt = true;

	public function __construct($x=null, $logging=false, $doIt=true) {

		if ($x) {
			if ($x instanceof DOMElement) {
				$this->nodeToEntry($x);
				$this->xmlDoc = $x->ownerDocument;
			} else {
				$this->setID($id);
			}
		}

		if ($logging !== false) $this->setLogging(true);
		if ($doIt === false) $this->setDoIt(false);
	} // End of __construct


	function nodeToEntry ($n) {
		if ($n instanceof DOMElement) {
			if ($n->nodeType == XML_ELEMENT_NODE && $n->nodeName == "entry") {
				foreach ($n->childNodes as $node) {
					if ($node->nodeType == XML_ELEMENT_NODE) {
						$base = ($node->hasAttribute("xml:base") ? $node->getAttribute("xml:base") : null);
						$lang = ($node->hasAttribute("xml:lang") ? $node->getAttribute("xml:lang") : null);
						switch ($node->nodeName) {
						case "id":
							$this->setID($node->nodeValue, $base, $lang);
							break;
						case "author":
							$nm = $node->getElementsByTagName("name");
							$nm = (count($nm)>0 ? $nm[0]->nodeValue : null);
							$em = $node->getElementsByTagName("email");
							$em = (count($em)>0 ? $em[0]->nodeValue : null);
							$uri = $node->getElementsByTagName("uri");
							$uri = (count($uri)>0 ? $uri[0]->nodeValue : null);
							$this->addAuthor($nm, $em, $uri, $base, $lang);
							break;
						case "category":
							$t = $node->getAttribute("term");
							$s = ($node->hasAttribute("scheme") ? $node->getAttribute("scheme") : null);
							$l = ($node->hasAttribute("label") ? $node->getAttribute("label") : null);
							$this->addCategory($t, $s, $l, $base, $lang);
							break;
						case "content":
							// Could have type, src, and type could be text, html, xhtml, or other.  Default to text
							$tp = ($node->hasAttribute("type") ? $tp = $node->getAttribute("type") : "text");
							$src = ($node->hasAttribute("src") ? $node->getAttribute("src") : null);
							$c = $node->nodeValue;
							$this->setContent($c, $tp, $src, $base, $lang);
							break;
						case "contributor":
							$nm = $node->getElementsByTagName("name");
							$nm = (count($nm)>0 ? $nm[0]->nodeValue : null);
							$em = $node->getElementsByTagName("email");
							$em = (count($em)>0 ? $em[0]->nodeValue : null);
							$uri = $node->getElementsByTagName("uri");
							$uri = (count($uri)>0 ? $uri[0]->nodeValue : null);
							$this->addContributor($nm, $em, $uri, $base, $lang);
							break;
						case "title":
							$type = ($node->hasAttribute("type") ? $node->getAttribute("type") : null);
							$this->setTitle($node->nodeValue, $type, $base, $lang);
							break;
						case "link":
							$rel = ($node->hasAttribute("rel") ? $node->getAttribute("rel") : null);
							$title = ($node->hasAttribute("title") ? $node->getAttribute("title") : null);
							$hreflang = ($node->hasAttribute("hreflang") ? $node->getAttribute("hreflang") : null);
							$type = ($node->hasAttribute("type") ? $node->getAttribute("type") : null);
							$length = ($node->hasAttribute("length") ? $node->getAttribute("length") : null);
							$this->addLink($node->getAttribute("href"), $rel, $title, $hreflang, $type, $length, $base, $lang);
							break;

						case "published":
							$this->setPublished($node->nodeValue, $base, $lang);
							break;
						case "rights":
							$this->setRights($node->nodeValue, $base, $lang);
							break;
						case "summary":
							$this->setSummary($node->nodeValue, $base, $lang);
							break;
						case "source":
							$this->setSource($node);
							break;
						case "updated":
							$this->setUpdated($node->nodeValue, $base, $lang);
							break;
						default:
							//Do stuff assuming this is an extension thingy.
							$this->addExtension($node);
						} // End of switch
					} // if ($node->nodeType == XML_ELEMENT_NODE)
				} // End of foreach child in node
			} // End of if it's an actual node of type entry
		} // End of if it's a DOMElement
	} // End of nodeToEntry

	function setID ($id, $b=null, $l=null) {
		$this->id = new atomElement($id, $b, $l);
	} // End of setID
	function getID () {return $this->id->getContent();}
	function getIDObj () {return $this->id;}

	function addCategory ($t, $s=null, $lbl=null, $b, $l) {
		$t = trim($t);
		if (preg_match("/^\w+$/", $t)) {
			$cat = new atomCategory($t, $s, $lbl, $b, $l);
			$this->categories[$t] = $cat;
		}
	} // End of addCategory
	function getCategories () { return $this->categories; }

	function setContent ($c, $t="text", $src=null, $b=null, $l=null) {	// type could be text, html, xhtml, otherMediaType
		if ($c instanceof atomEntryContent) {
			$this->content = $c;
		} else {
			$this->content = new atomEntryContent($c, $t, $b, $l);
			if ($src) $this->content->setSrc($src);
		}
	} // End of setContent
	function getContent() { return $this->content->getContent();}
	function getContentObj() { return $this->content;}


	function setTitle ($c, $t="text", $b=null, $l=null) {
		$this->title = new atomContent($c, $t, $b=null, $l=null);
	} // End of setTitle
	function getTitle () { return $this->title->getContent();}
	function getTitleObj () { return $this->title;}

	function addLink($h=null, $r=null, $title=null, $hl=null, $type=null, $len=null, $b=null, $l=null) {
		if ($h) {
			array_push($this->links, new atomLink($h, $r, $title, $hl, $type, $len, $b, $l));
		}
	} // End of addLink
	function getLinks() { return $this->links;}

	function setPublished ($p, $b=null, $l=null) {
		$this->published = new atomDate($p);
		if ($b) $this->published->setBase($b);
		if ($l) $this->published->setLang($l);
	} // End of setPublished
	function getPublished () { return $this->published->getContent(); }
	function getPublishedObj () { return $this->published; }

	function setRights ($i, $b=null, $l=null) {
		$this->rights = new atomElement($i, $b, $l);
	} // End of setRights
	function getRights () {return $this->rights->getContent();}
	function getRightsObj() { return $this->rights; }

	function setSummary ($c, $t="text", $b=null, $l=null) {
		$this->summary = new atomContent($c, $t, $b, $l);
	} // End of setSummary
	function getSummary () {return $this->summary->getContent();}
	function getSummaryObj () {return $this->summary;}


	function setUpdated ($t, $b=null, $l=null) {
		$this->updated = new atomDate($t. $b, $l);
	} // End of setUpdated
	function getUpdated () {return $this->updated->getContent();}
	function getUpdatedObj () {return $this->updated;}

	function addAuthor ($n, $e=null, $i=null, $b=null, $l=null) {
		array_push($this->author, new atomPerson($n, $e, $i, $b, $l));
	} // End of addAuthor
	function getAuthors() { return $this->author;}

	function addContributor ($n, $e=null, $i=null, $b=null, $l=null) {
		$n = trim($n);
		$isThere = false;
		$contributors = $this->getContributors();
		for ($i = 0; $i<count($contributors) && !$isThere; $i++) {
			$pattern = "/^$n$/i";
			if (preg_match($pattern, $contributors[$i]->getName())) $isThere=true;
		}
		if (!$isThere) array_push($this->contributors, new atomPerson($n, $e, $i, $b, $l));
	} // End of addContributor
	function getContributors() { return $this->contributors; }

	function setSource ($source=null) {
		// Should this just be an associative array, or a reference to an atomFeed object?
		// Ohhh....how about both?  Accept one arguement.  If it's an instance of an atomFeed, get the info from that;
		// If it's an array, then get the info from that.
		// Call me lazy, but I'm just going with the object....it's just a reference anyway, so it should be less
		// Memory than an array.  Then just have a switch in the toString function that doesn't go through the entities
		$rv = true;
		if ($source) {
			if ($source instanceof atomFeed) {
				$this->source = clone $source;
				$this->source->clearEntries();

			} else if ($source instanceof DOMElement || is_string($source)) {
				//if ($source->nodeType == XML_ELEMENT_NODE && $source->nodeName == "feed") {
					$srcFeed = new atomFeed();
					$srcFeed->load($source, false);
					$this->source = $srcFeed;
				//}

			} else {
				$rv = "This requires an atomFeed object, DOMElement of <feed>, or string starting with <feed or <source.";
				if ($this->logging) echo "<p class=\"nordburgLogging\">$rv</p>\n";
				throw new RuntimeException($rv);
			}
		}
		return $rv;
	} // End of setSource
	function getSource () { return $this->source; }


	function createNode ($xmlDoc=null) {
		// This sould be called from a saving function to create an XML <node>.
		// But you need the XMLDomdocument to create the node
		$rn = false;
		if ($xmlDoc && $xmlDoc instanceof DOMDocument) {
			$this->xmlDoc = $xmlDoc;
			$rn = $this->xmlDoc->createElement("entry");
			$xmlDoc->appendChild($rn);

			// Note for tomorrow:  add atom::setCommonAttributes here
			
			// title
			if ($this->title) {
				$titleNode = $this->xmlDoc->createElement("title", $this->getTitle());
				atomEntry::setCommonAttributes($titleNode, $this->getTitleObj());
				$rn->appendChild($titleNode);
			}
			
			// ID
			if ($this->id) {
				$idNode = $this->xmlDoc->createElement("id", $this->getID());
				atomEntry::setCommonAttributes($idNode, $this->getIDObj());
				$rn->appendChild($idNode);
			}
			
			// Author
			for ($i = 0; $i<count($this->author); $i++) {
				$authNode = $this->xmlDoc->createElement("author");
				$name = $this->author[$i]->getName();
				$email = $this->author[$i]->getEmail();
				$uri = $this->author[$i]->getURI();
				$base = $this->author[$i]->getBase();
				$lang = $this->author[$i]->getLang();
				if ($name) {
					$nameNode = $this->xmlDoc->createElement("name", $name);
					$authNode->appendChild($nameNode);
				}
				if ($uri) {
					$uriNode = $this->xmlDoc->createElement("uri", $uri);
					$authNode->appendChild($uriNode);
				}
				if ($email) {
					$emailNode = $this->xmlDoc->createElement("email", $email);
					$authNode->appendChild($emailNode);
				}
				if ($base) $authNode->setAttribute("xml:base", $base);
				if ($lang) $authNode->setAttribute("xml:lang", $lang);

				$rn->appendChild($authNode);
			}


			// Categories
			for ($i = 0; $i < count($this->categories); $i++) {
				$catNode = $this->xmlDoc->createElement("category");
				$catNode->setAttribute("term", $this->categories[$i]->getTerm());
				$scheme = $this->categories[$i]->getScheme();
				$label = $this->categories[$i]->getLabel();
				$base = $this->categories[$i]->getBase();
				$lang = $this->categories[$i]->getLang();
				
				if ($scheme) $catNode->setAttribute("scheme", $scheme);
				if ($label) $catNode->setAttribute("label", $label);
				if ($base) $catNode->setAttribute("xml:base", $base);
				if ($lang) $catNode->setAttribute("xml:lang", $lang);
				$rn->appendChild($catNode);
			}

			// Content
			if ($this->content) {
				$contentNode = $this->xmlDoc->createElement("content");
				$contentObj = $this->getContentObj();
				atomEntry::setCommonAttributes($contentNode, $contentObj);
				$t = $contentObj->getType();
				$src = $contentObj->getSrc();
				$contentNode->setAttribute("type", $t);
				if ($src) $contentNode->setAttribute("src", $src);
				
				$cont = $contentObj->getContent();
				if ($cont && $t == "xhtml") {
					if (!preg_match("/^(<xml:)?div [^>]*xmlns/i", $cont)) $cont = "<div xmlns=\"http://www.w3.org/1999/xhtml\">$cont</div>";
				}
				$contentNode->textContent = $cont;
				$rn->appendChild($contentNode);
			}

			// Contributors
			for ($i = 0; $i < count($this->contributor); $i++) {
				$contribNode = $this->xmlDoc->createElement("contributor");
				$name = $this->contributor[$i]->getName();
				$email = $this->contributor[$i]->getEmail();
				$uri = $this->contributor[$i]->getURI();
				$base = $this->contributor[$i]->getBase();
				$lang = $this->contributor[$i]->getLang();

				if ($base) $contribNode->setAttribute("xml:base", $base);
				if ($lang) $contribNode->setAttribute("xml:lang", $lang);

				if ($name) {
					$nameNode = $this->xmlDoc->createElement("name", $name);
					$contribNode->appendChild($nameNode);
				}
				if ($uri) {
					$uriNode = $this->xmlDoc->createElement("uri", $uri);
					$contribNode->appendChild($uriNode);
				}
				if ($email) {
					$emailNode = $this->xmlDoc->createElement("email", $email);
					$contribNode->appendChild($emailNode);
				}

		
				$rn->appendChild($contribNode);
			}
			
			// Links
			for ($i = 0; $i < count($this->links); $i++) {
				$linkNode = $this->xmlDoc->createElement("link");
				
				$rel = $this->links[$i]->getRel();
				$type = $this->links[$i]->getType();
				$hreflang = $this->links[$i]->getHreflang();
				$title = $this->links[$i]->getTitle();
				$length = $this->links[$i]->getLength();
				$base = $this->links[$i]->getBase();
				$lang = $this->links[$i]->getLang();
				

				$linkNode->setAttribute("href", $this->links[$i]->getHref());
				if ($rel) $linkNode->setAttribute("rel", $rel);
				if ($type) $linkNode->setAttribute("type", $type);
				if ($hreflang) $linkNode->setAttribute("hreflang", $hreflang);
				if ($title) $linkNode->setAttribute("title", $title);
				if ($length) $linkNode->setAttribute("length", $length);
				if ($base) $linkNode->setAttribute("xml:base", $base);
				if ($lang) $linkNode->setAttribute("xml:lang", $lang);

				$rn->appendChild($linkNode);
			}

			// Published
			if ($this->published) {
				$publishedNode = $this->xmlDoc->createElement("published", $this->getPublished());
				atomEntry::setCommonAttributes($publishedNode, $this->getPublishedObj());
				$rn->appendChild($publishedNode);
			}

			// Rights
			if ($this->rights) {
				$rightsNode = $this->xmlDoc->createElement("rights", $this->getRights());
				atomEntry::setCommonAttributes($rightsNode, $this->getRightsObj());
				$rn->appendChild($rightsNode);
			}

			// Summary
			if ($this->summary) {
				$summaryNode = $this->xmlDoc->createElement("summary", $this->getSummary());
				atomEntry::setCommonAttributes($summaryNode, $this->getSummaryObj());
				$rn->appendChild($summaryNode);
			}


			// Do Source.  Ughhh
			if ($this->source) {
				$sourceNode->feedToXML(false, "source");
				$rn->appendChild($sourceNode);
			}
			
			// Updated
			if ($this->updated) {
				$updatedNode = $this->xmlDoc->createElement("updated", $this->getUpdated());
				atomEntry::setCommonAttributes($updatedNode, $this->getUpdatedObj());
				$rn->appendChild($updatedNode);
			}

			// Extensions
			for ($i = 0; $i<count($this->extensions); $i++) {
				//$ext = $this->xmlDoc->loadXML($this->extensions[$i]);
				$ext = $this->extensions[$i];
				$ext2 = $xmlDoc->importNode($ext, true);
				$rn->appendChild($ext2);
				// May have to use importNode() here.
			}


		} else {
			// throw exception?
		}
		return $rn;
	} // End of createNode

	function setBase ($b) {
		$this->base = $b;
	} // End of setBase
	function getBase () { return $this->base; }

	function setLang ($l) {
		if (preg_match("/^[a-z]{2,3}(-[A-Z]{2,3})?$/", $l)) $this->lang = $l;
	} // End of setLang
	function getLang () { return $this->lang; }


	function addExtension ($ex) {
		array_push($this->extensions, $ex); //->ownerDocument->saveXML($ex));
	} // End of addExtension
	function getExtensions () { return $this->extensions; }

	function setLogging($l) {
		if (is_bool($l)) {
			$this->logging = $l;
		}
	} // End of setLogging
	function getLogging(){ return $this->logging; }

	function setDoIt($di) {
		if (is_bool($di)) {
			$this->doIt = $di;
		}
	} // End of setDoIt
	function getDoIt() { return $this->doIt;}

	private static function setCommonAttributes ($node, $prop) {
		$base = $prop->getBase();
		$lang = $prop->getLang();
		if ($base) $node->setAttribute("xml:base", $base);
		if ($lang) $node->setAttribute("xml:lang", $lang);
	} // End of setCommonAttributes


} // End of class atomEntry

class atomElement {
	protected $content = null;
	protected $base = null;
	protected $lang= null;

	public function __construct($c=null, $b=null, $l=null) {
		$this->setContent($c);

		if ($b) $this->setBase($b);
		if ($l) $this->setLang($l);
	} // End of __construct

	function setContent ($c) {
		$this->content = $c;
	} // End of setContent
	function getContent () {return $this->content;}

	function setLang ($l) {
		if (preg_match("/^[a-z]{2,3}(-[A-Z]{2,3})?$/", $l)) {
			$this->lang = $l;
		} else {
			throw new InvalidArgumentException("invalid lang format");
		}

	} // End of setLang
	function getLang () { return $this->lang;}

	function setBase ($b) {
		$this->base = $b;
	} // End of setBase
	function getBase () { return $this->base; }

} // End of atomElement

class atomDate extends atomElement {
	function setContent ($d) {
		if (preg_match("/\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(\.\d+)?(Z|[-+]\d\d:\d\d)/", $d)) {
			$this->content = $d;
		} else {
			throw new InvalidArgumentException("invalid date format");
		}
	} // End of setContent
} // End of atomDate


class atomContent extends atomElement {
	private $type="text";
	private $src = null;

	public function __construct($c="", $t="text", $b=null,$l=null) {
		parent::__construct($c, $b, $l);
		$this->setType($t);
	} // End of __construct

	function setType ($t) {
		if (preg_match("/^(text|html|xhtml)$/i", $t)) $this->type = $t;
	} // End of setType
	function getType () { return $this->type; }

	function setSrc ($s) {
		$this->src = $s;
	} // End of setSrc
	function getSrc () { return $this->src; }


} // End of atomContent

class atomEntryContent extends atomContent {
	//public function __construct($c, $t="text") {
		//parent::__construct($c, $t);	// Maybe you don't need this?
	//} // End of __construct

	function setType ($t) {
		$this->type = $t;
	} // End of setType

} // End of atomEntryContent

class atomPerson extends atomElement {
	private $name = null;
	private $email = null;
	private $uri = null;
	private $extensions = array();

	public function __construct($n=null, $e=null, $u=null, $b=null, $l=null) {
		if ($n instanceof DOMElement) {
			foreach ($n->childNodes as $node) {
				$this->nodeToPerson($n);
			}
		} else {
			if ($n) $this->setName($n);
			if ($e) $this->setEmail($e);
			if ($u) $this->setURI($u);
			if ($b) $this->setBase($b);
			if ($l) $this->setLang($l);
		}
	} // End of __construct

	function nodeToPerson ($n) {
		if ($n instanceof DOMElement) {
			$base = ($n->hasAttribute("xml:base") ? $n->getAttribute("xml:base") : null);
			$lang = ($n->hasAttribute("xml:lang") ? $n->getAttribute("xml:lang") : null);
			if ($base) $this->setBase($base);
			if ($lang) $this->setLang($lang);
			foreach ($n->childNodes as $node) {
				if ($node->nodeType == XML_ELEMENT_NODE) {

					switch ($n->nodeName) {
						case "name":
							$this->setName($n->nodeValue);
							break;
						case "email":
							$this->setEmail($n->nodeValue);
							break;
						case "uri":
							$this->setURI($n->nodeValue);
							break;
						default:
							$this->addExtension($n);
					}
				}
			}
		}
	} // End of nodeToPerson
	function setName ($nm) {
		$this->name = $nm;
	} // End of setName
	function getName() { return $this->name;}

	function setEmail ($em) {
		$this->email = $em;
	} // End of setEmail
	function getEmail() { return $this->email;}

	function setURI ($uri) {
		$this->uri = $uri;
	} // End of setURI
	function getURI() { return $this->uri;}

	function addExtension ($ex) {
		array_push($this->extensions, $ex); //->ownerDocument->saveXML($ex));
	} // End of addExtension
	function getExtensions () { return $this->extensions; }

} // End of class atomPerson

class atomGenerator extends atomElement {
	private $uri = null;
	private $version = null;

	public function __construct($g, $u, $v, $b, $l) {
		parent::__construct($g, $b, $l);
		if ($u) $this->setURI($u);
		if ($v) $this->setVersion($v);
	} // End of __construct
	function setURI($u) {
		$this->uri = $u;
	} // End of setURI
	function getURI () { return $this->uri; }
		function setVersion($v) {
		// The spec doesn't give a version format, so....
		$this->version = $v;
	} // End of setVersion
	function getVersion() { return $this->version; }
} // End of class atomGenerator

class atomCategory extends atomElement {
	private $term = null;
	private $scheme = null;
	private $label = null;

	public function __construct($t, $s=null, $lbl=null, $b=null, $l=null) {
		$this->setTerm($t);
		if ($s) $this->setScheme($s);
		if ($lbl) $this->setLabel($lbl);
		if ($b) $this->setBase($b);
		if ($l) $this->setLang($l);
	} // End of __construct

	function setTerm ($t) {
		$this->term = $t;
	} // End of setTerm
	function getTerm() { return $this->term;}

	function setScheme ($s) {
		// Should prolly put in a cleanser here
		$this->scheme = $s;
	} // End of setScheme
	function getScheme() { return $this->scheme;}

	function setLabel ($l) {
		$this->label = $l;
	} // End of setLabel
	function getLabel() { return $this->label;}

}// End of atomCategory

class atomLink extends atomElement {
	private $href = null;
	private $rel = null;		// alternate, related, self, enclosure, via
	private $title = null;
	private $hreflang = null;
	private $type = null;		// text, html, xhtml
	private $length = null;

	public function __construct($h=null, $r=null, $title=null, $hl=null, $type=null, $len=null, $b=null, $l=null) {
		$this->setHref($h);
		if ($r) $this->setRel($r);
		if ($type) $this->setType($type);
		if ($hl) $this->setHrefLang($hl);
		if ($title) $this->setTitle($title);
		if ($len) $this->setLength($len);
		if ($b) $this->setBase($b);
		if ($l) $this->setLang($l);
	} // End of __construct

	function setHref ($t) {
		$this->href = $t;
	} // End of setHref
	function getHref() { return $this->href;}

	function setRel ($t) {
		if (preg_match("/^(alternate|related|self|enclosure|via)$/i", $t)) $this->rel = $t;
	} // End of setRel
	function getRel() { return $this->rel;}


	function setType ($t) {
		$this->type = $t;
	} // End of setType
	function getType() { return $this->type;}

	function setHreflang ($l) {
		if (preg_match("/^[a-z]{2,3}(-[A-Z]{2,3}?$/", $l)) {
			$this->hreflang = $l;
		} else {
			throw new InvalidArgumentException("invalid lang format");
		}
	} // End of setHreflang
	function getHreflang() { return $this->hreflang;}

	function setTitle ($t) {
		$this->title = $t;
	} // End of setTitle
	function getTitle() { return $this->title;}

	function setLength ($t) {
		$this->length = $t;
	} // End of setLength
	function getLength() { return $this->length;}

} // End of atomLink


?>
