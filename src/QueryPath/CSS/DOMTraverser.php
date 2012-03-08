<?php
/** @file
 * Traverse a DOM.
 */

namespace QueryPath\CSS;

/**
 * Traverse a DOM, finding matches to the selector.
 *
 * This traverses a DOMDocument and attempts to find
 * matches to the provided selector.
 *
 * \b How this works
 *
 * This performs a bottom-up search. On the first pass,
 * it attempts to find all of the matching elements for the
 * last simple selector in a selector.
 *
 * Subsequent passes attempt to eliminate matches from the
 * initial matching set.
 *
 * Example:
 *
 * Say we begin with the selector `foo.bar baz`. This is processed
 * as follows:
 *
 * - First, find all baz elements.
 * - Next, for any baz element that does not have foo as an ancestor,
 *   eliminate it from the matches.
 * - Finally, for those that have foo as an ancestor, does that foo
 *   also have a class baz? If not, it is removed from the matches.
 *
 * \b Extrapolation
 *
 * Partial simple selectors are almost always expanded to include an
 * element.
 *
 * Examples:
 *
 * - `:first` is expanded to `*:first`
 * - `.bar` is expanded to `*.bar`.
 * - `.outer .inner` is expanded to `*.outer *.inner`
 *
 * The exception is that IDs are sometimes not expanded, e.g.:
 *
 * - `#myElement` does not get expanded
 * - `#myElement .class` \i may be expanded to `*#myElement *.class`
 *   (which will obviously not perform well).
 */
class DOMTraverser implements Traverser {

  protected $matches = array();
  protected $selector;
  protected $dom;
  protected $initialized = TRUE;

  /**
   * Build a new DOMTraverser.
   *
   * This requires a DOM-like object or collection of DOM nodes.
   */
  public function __construct($dom) {
    // This assumes a DOM. Need to also accomodate the case
    // where we get a set of elements.
    $this->initialized = FALSE;
    $this->dom = $dom;
    $this->matches = new \SplObjectStorage();
    $this->matches->attach($this->dom);
  }

  public function debug($msg) {
    fwrite(STDOUT, PHP_EOL . $msg);
  }

  public function find($selector) {
    // Setup
    $handler = new Selector();
    $parser = new Parser($selector, $handler);
    $parser->parse();
    $this->selector = $handler;

    $selector = $handler->toArray();

    // Initialize matches if necessary.
    if (!$this->initialized) {
      $this->initialMatch($selector[0]);
      $this->initialized = TRUE;
    }

    $found = $this->newMatches();
    foreach ($this->matches as $candidate) {
      if ($this->matchesSelector($candidate, $selector)) {
        //$this->debug('Attaching ' . $candidate->nodeName);
        $found->attach($candidate);
      }
    }
    $this->setMatches($found);

    return $this;
  }

  public function matches() {
    return $this->matches;
  }

  /**
   * Check whether the given node matches the given selector.
   *
   * @param object DOMNode
   *   The DOMNode to check.
   * @param array Selector->toArray()
   *   The Selector to check.
   * @retval boolean
   *   A boolean TRUE if the node matches, false otherwise.
   */
  public function matchesSelector($node, $selector) {
    $res = TRUE;
    $i = 0;
    do {
      //$this->debug("Selector: " . $selector[$i] . " on " . $node->nodeName);
      $res = $this->matchesSimpleSelector($node, $selector[$i]);
    }
    while ($res == TRUE && isset($selector[++$i]));

    return $res;
  }

  /**
   * Performs a match check on a SimpleSelector.
   *
   * Where matchesSelector() does a check on an entire selector,
   * this checks only a simple selector (plus an optional
   * combinator).
   *
   * @param object DOMNode
   *   The DOMNode to check.
   * @param object SimpleSelector
   *   The Selector to check.
   * @retval boolean
   *   A boolean TRUE if the node matches, false otherwise.
   */
  public function matchesSimpleSelector($node, $selector) {
    // Note that this will short circuit as soon as one of these
    // returns FALSE.
    return $this->matchElement($node, $selector->element, $selector->ns)
      && $this->matchAttributes($node, $selector->attributes)
      && $this->matchId($node, $selector->id)
      && $this->matchClasses($node, $selector->classes)
      && $this->matchPseudoClasses($node, $selector->pseudoClasses)
      && $this->matchPseudoElements($node, $selector->pseudoElements);
      //&& $this->matchCombinator($node, $selector->combinator);
  }

  /**
   * Get the intial match set.
   *
   * This should only be executed when not working with
   * an existing match set.
   */
  protected function initialMatch($selector) {
    $element = $selector->element;

    // If no element is specified, we have to start with the
    // entire document.
    if ($element == NULL) {
      $element = '*';
    }

    if (!empty($ns)) {
      throw new \Exception('FIXME: Need namespace support.');
    }

    $found = $this->newMatches();
    foreach ($this->getMatches() as $node) {
      $nl = $node->getElementsByTagName($element);
      $this->attachNodeList($nl, $found);
    }
    $this->setMatches($found);

    $selector->element = NULL;
  }

  /**
   * Checks to see if the DOMNode matches the given element selector.
   */
  protected function matchElement($node, $element, $ns = NULL) {
    if (empty($element)) {
      return TRUE;
    }

    if (!empty($ns)) {
      throw new \Exception('FIXME: Need namespace support.');
    }

    return TRUE;

  }

  /**
   * Checks to see fi the given DOMNode matches an "any element" (*).
   */
  protected function matchAnyElement($node) {
    $ancestors = $this->ancestors($node);

    return count($ancestors) > 0;
  }

  /**
   * Get a list of ancestors to the present node.
   */
  protected function ancestors($node) {
    $buffer = array();
    $parent = $node;
    while (($parent = $parent->parentNode) !== NULL) {
      $buffer[] = $parent;
    }
    return $buffer;
  }

  /**
   * Check to see if DOMNode has all of the given attributes.
   */
  protected function matchAttributes($node, $attributes) {
    if (empty($attributes)) {
      return TRUE;
    }

    foreach($attributes as $attr) {
      // FIXME
      if (isset($attr['ns'])) {
        throw new \Exception('FIXME: Attribute namespace support missing.');
      }
      $name = $attr['name'];
      if ($node->hasAttribute($name)) {
        if (isset($attr['value'])) {
          $attrVal = $node->getAttribute($name);
          $res = $this->matchAttributeValue($attr['value'], $attrVal, $attr['op']);

          // As soon as we fail to match, return FALSE.
          if (!$res) {
            return FALSE;
          }
        }
      }
      // If the element doesn't have the attribute, fail the test.
      else {
        return FALSE;
      }
    }
    return TRUE;
  }
  /**
   * Check for attr value matches based on an operation.
   */
  protected function matchAttributeValue($needle, $haystack, $operation) {

    if (strlen($haystack) < strlen($needle)) return FALSE;

    // According to the spec:
    // "The case-sensitivity of attribute names in selectors depends on the document language."
    // (6.3.2)
    // To which I say, "huh?". We assume case sensitivity.
    switch ($operation) {
      case EventHandler::isExactly:
        return $needle == $haystack;
      case EventHandler::containsWithSpace:
        // XXX: This needs testing!
        return preg_match('/\b/', $haystack) == 1;
        //return in_array($needle, explode(' ', $haystack));
      case EventHandler::containsWithHyphen:
        return in_array($needle, explode('-', $haystack));
      case EventHandler::containsInString:
        return strpos($haystack, $needle) !== FALSE;
      case EventHandler::beginsWith:
        return strpos($haystack, $needle) === 0;
      case EventHandler::endsWith:
        //return strrpos($haystack, $needle) === strlen($needle) - 1;
        return preg_match('/' . $needle . '$/', $haystack) == 1;
    }
    return FALSE; // Shouldn't be able to get here.
  }

  /**
   * Check that the given DOMNode has the given ID.
   */
  protected function matchId($node, $id) {
    if (empty($id)) {
      return TRUE;
    }
    return $node->hasAttribute('id') && $node->getAttribute('id') == $id;
  }
  /**
   * Check that the given DOMNode has all of the given classes.
   */
  protected function matchClasses($node, $classes) {
    if (empty($classes)) {
      return TRUE;
    }

    if (!$node->hasAttribute('class')) {
      return FALSE;
    }

    $eleClasses = preg_split('/\s+/', $node->getAttribute('class'));
    if (empty($eleClasses)) {
      return FALSE;
    }

    // The intersection should match the given $classes.
    $missing = array_diff($classes, array_intersect($classes, $eleClasses));

    return count($missing) == 0;
  }
  protected function matchPseudoClasses($node, $pseudoClasses) {
    return TRUE;
  }
  protected function matchPseudoElements($node, $pseudoElements) {
    return TRUE;
  }

  protected function newMatches() {
    return new \SplObjectStorage();
  }

  /**
   * Get the internal match set.
   * Internal utility function.
   */
  protected function getMatches() {
    return $this->matches();
  }

  /**
   * Set the internal match set.
   *
   * Internal utility function.
   */
  protected function setMatches($matches) {
    $this->matches = $matches;
  }

  /**
   * Attach all nodes in a node list to the given \SplObjectStorage.
   */
  public function attachNodeList(\DOMNodeList $nodeList, \SplObjectStorage $splos) {
    foreach ($nodeList as $item) $splos->attach($item);
  }

  public function getDocument() {
    return $this->dom;
  }

}