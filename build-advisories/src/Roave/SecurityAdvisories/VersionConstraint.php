<?php

declare(strict_types=1);

namespace Roave\SecurityAdvisories;

/**
 * A simple version constraint - naively assumes that it is only about ranges like ">=1.2.3,<4.5.6"
 */
final class VersionConstraint
{
    const CLOSED_RANGE_MATCHER     = '/^>(=?)\s*((?:\d+\.)*\d+)\s*,\s*<(=?)\s*((?:\d+\.)*\d+)$/';
    const LEFT_OPEN_RANGE_MATCHER  = '/^<(=?)\s*((?:\d+\.)*\d+)$/';
    const RIGHT_OPEN_RANGE_MATCHER = '/^>(=?)\s*((?:\d+\.)*\d+)$/';

    /**
     * @var string|null
     */
    private $constraintString;

    /**
     * @var bool whether the lower bound is included or excluded
     */
    private $lowerBoundIncluded = false;

    /**
     * @var Version|null the upper bound of this constraint, null if unbound
     */
    private $lowerBound;

    /**
     * @var bool whether the upper bound is included or excluded
     */
    private $upperBoundIncluded = false;

    /**
     * @var Version|null the upper bound of this constraint, null if unbound
     */
    private $upperBound;

    private function __construct()
    {
    }

    /**
     * @param string $versionConstraint
     *
     * @return self
     *
     * @throws \InvalidArgumentException
     */
    public static function fromString(string $versionConstraint) : self
    {
        $constraintString = (string) $versionConstraint;
        $instance         = new self();

        if (preg_match(self::CLOSED_RANGE_MATCHER, $constraintString, $matches)) {
            $instance->lowerBoundIncluded = (bool) $matches[1];
            $instance->upperBoundIncluded = (bool) $matches[3];
            $instance->lowerBound         = Version::fromString($matches[2]);
            $instance->upperBound         = Version::fromString($matches[4]);

            return $instance;
        }

        if (preg_match(self::LEFT_OPEN_RANGE_MATCHER, $constraintString, $matches)) {
            $instance->upperBoundIncluded = (bool) $matches[1];
            $instance->upperBound         = Version::fromString($matches[2]);

            return $instance;
        }

        if (preg_match(self::RIGHT_OPEN_RANGE_MATCHER, $constraintString, $matches)) {
            $instance->lowerBoundIncluded = (bool) $matches[1];
            $instance->lowerBound         = Version::fromString($matches[2]);

            return $instance;
        }

        $instance->constraintString = $constraintString;

        return $instance;
    }

    public function isSimpleRangeString() : bool
    {
        return null === $this->constraintString;
    }

    public function getConstraintString() : string
    {
        if (null !== $this->constraintString) {
            return $this->constraintString;
        }

        $parts = [];

        if ($this->lowerBound) {
            $parts[] = '>' . ($this->lowerBoundIncluded ? '=' : '') . $this->lowerBound->getVersion();
        }

        if ($this->upperBound) {
            $parts[] = '<' . ($this->upperBoundIncluded ? '=' : '') . $this->upperBound->getVersion();
        }

        return implode(',', $parts);
    }

    public function isLowerBoundIncluded() : bool
    {
        return $this->lowerBoundIncluded;
    }

    public function getLowerBound() : ?Version
    {
        return $this->lowerBound;
    }

    public function getUpperBound() : ?Version
    {
        return $this->upperBound;
    }

    public function isUpperBoundIncluded() : bool
    {
        return $this->upperBoundIncluded;
    }

    public function canMergeWith(self $other) : bool
    {
        return $this->contains($other)
            || $other->contains($this)
            || $this->overlapsWith($other)
            || $other->overlapsWith($this);
    }

    /**
     * @param VersionConstraint $other
     *
     * @return VersionConstraint
     *
     * @throws \LogicException
     */
    public function mergeWith(self $other) : self
    {
        if ($this->contains($other)) {
            return $this;
        }

        if ($other->contains($this)) {
            return $other;
        }

        if ($this->overlapsWith($other)) {
            return $this->mergeWithOverlapping($other);
        }

        if ($other->overlapsWith($this)) {
            return $other->mergeWithOverlapping($this);
        }

        throw new \LogicException(sprintf(
            'Cannot merge %s "%s" with %s "%s"',
            self::class,
            $this->getConstraintString(),
            self::class,
            $other->getConstraintString()
        ));
    }

    private function contains(self $other) : bool
    {
        return $this->isSimpleRangeString()  // cannot compare - too complex :-(
            && $other->isSimpleRangeString() // cannot compare - too complex :-(
            && $this->containsLowerBound($other->lowerBoundIncluded, $other->lowerBound)
            && $this->containsUpperBound($other->upperBoundIncluded, $other->upperBound);
    }

    private function containsLowerBound(bool $otherLowerBoundIncluded, ?Version $otherLowerBound) : bool
    {
        if (! $this->lowerBound) {
            return true;
        }

        if (! $otherLowerBound) {
            return false;
        }

        if (($this->lowerBoundIncluded === $otherLowerBoundIncluded) || $this->lowerBoundIncluded) {
            return $otherLowerBound->isGreaterOrEqualThan($this->lowerBound);
        }

        return $otherLowerBound->isGreaterThan($this->lowerBound);
    }

    private function containsUpperBound(bool $otherUpperBoundIncluded, ?Version $otherUpperBound) : bool
    {
        if (! $this->upperBound) {
            return true;
        }

        if (! $otherUpperBound) {
            return false;
        }

        if (($this->upperBoundIncluded === $otherUpperBoundIncluded) || $this->upperBoundIncluded) {
            return $this->upperBound->isGreaterOrEqualThan($otherUpperBound);
        }

        return $this->upperBound->isGreaterThan($otherUpperBound);
    }

    private function overlapsWith(VersionConstraint $other) : bool
    {
        if (! $this->isSimpleRangeString() && $other->isSimpleRangeString()) {
            return false;
        }

        if ($this->contains($other) || $other->contains($this)) {
            return false;
        }

        return $this->strictlyContainsOtherBound($other->lowerBound)
            xor $this->strictlyContainsOtherBound($other->upperBound);
    }

    /**
     * @param VersionConstraint $other
     *
     * @return self
     *
     * @throws \LogicException
     */
    private function mergeWithOverlapping(VersionConstraint $other) : self
    {
        if (! $this->overlapsWith($other)) {
            throw new \LogicException(sprintf(
                '%s "%s" does not overlap with %s "%s"',
                self::class,
                $this->getConstraintString(),
                self::class,
                $other->getConstraintString()
            ));
        }

        if ($this->strictlyContainsOtherBound($other->lowerBound)) {
            $instance = new self();

            $instance->lowerBound         = $this->lowerBound;
            $instance->lowerBoundIncluded = $this->lowerBoundIncluded;
            $instance->upperBound         = $other->upperBound;
            $instance->upperBoundIncluded = $other->upperBoundIncluded;

            return $instance;
        }

        $instance = new self();

        $instance->lowerBound         = $other->lowerBound;
        $instance->lowerBoundIncluded = $other->lowerBoundIncluded;
        $instance->upperBound         = $this->upperBound;
        $instance->upperBoundIncluded = $this->upperBoundIncluded;

        return $instance;
    }

    /**
     * @param Version|null $bound
     *
     * @return bool
     *
     * Note: most of the limitations/complication probably go away if we define a `Bound` VO
     */
    private function strictlyContainsOtherBound(?Version $bound) : bool
    {
        if (! $bound) {
            return false;
        }

        if (! $this->lowerBound) {
            return $this->upperBound->isGreaterThan($bound);
        }

        if (! $this->upperBound) {
            return $bound->isGreaterThan($this->lowerBound);
        }

        return $bound->isGreaterThan($this->lowerBound) && $this->upperBound->isGreaterThan($bound);
    }
}
