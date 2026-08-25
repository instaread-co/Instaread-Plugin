#!/usr/bin/env python3
"""
Rewrite PHP null coalesce `??` to `isset() ? : $default` for PHP 5.6 compat.

Correct token-aware version. Reads a PHP source file, walks it character-by-
character tracking string / comment state, and rewrites each `??` occurrence
that's actually PHP code (not inside a string or comment) into an isset()
ternary.

For each ?? found:
  - LHS operand: the immediately preceding PHP variable/access expression
    (walks backward from ?? through valid variable-access chars & balanced
    brackets to the leading $).
  - RHS: everything after ?? on the same line up to a natural break point
    (comma, semicolon, or the ) that closes the surrounding call).

Wraps the whole thing in parens: `(isset(LHS) ? LHS : RHS)`.
"""
import sys, re

if len(sys.argv) != 3:
    print(f'usage: {sys.argv[0]} <input.php> <output.php>')
    sys.exit(1)

with open(sys.argv[1]) as f:
    src = f.read()

# Step 1: build a mask marking every char as CODE / STRING / COMMENT so we
# only rewrite ?? that's actually PHP code.
mask = bytearray(len(src))  # 0=CODE, 1=STRING, 2=COMMENT
i = 0
n = len(src)
while i < n:
    c = src[i]
    # Line comment //
    if c == '/' and i + 1 < n and src[i+1] == '/':
        # go until \n
        while i < n and src[i] != '\n':
            mask[i] = 2
            i += 1
        continue
    # Block comment /* ... */
    if c == '/' and i + 1 < n and src[i+1] == '*':
        mask[i] = 2; mask[i+1] = 2; i += 2
        while i < n - 1 and not (src[i] == '*' and src[i+1] == '/'):
            mask[i] = 2
            i += 1
        if i < n: mask[i] = 2
        if i + 1 < n: mask[i+1] = 2
        i += 2
        continue
    # Line comment #
    if c == '#':
        while i < n and src[i] != '\n':
            mask[i] = 2
            i += 1
        continue
    # Single-quoted string
    if c == "'":
        mask[i] = 1; i += 1
        while i < n and src[i] != "'":
            if src[i] == '\\' and i + 1 < n:
                mask[i] = 1; mask[i+1] = 1; i += 2; continue
            mask[i] = 1; i += 1
        if i < n: mask[i] = 1; i += 1
        continue
    # Double-quoted string
    if c == '"':
        mask[i] = 1; i += 1
        while i < n and src[i] != '"':
            if src[i] == '\\' and i + 1 < n:
                mask[i] = 1; mask[i+1] = 1; i += 2; continue
            mask[i] = 1; i += 1
        if i < n: mask[i] = 1; i += 1
        continue
    # Heredoc/nowdoc — not present in this file (grep'd) so skip
    i += 1

# Step 2: find every ?? in CODE space
sites = []
i = 0
while i < n - 1:
    if src[i] == '?' and src[i+1] == '?' and mask[i] == 0 and mask[i+1] == 0:
        # Skip ??=  (null coalesce assignment, PHP 7.4+ — none in file, but be safe)
        if i + 2 < n and src[i+2] == '=':
            i += 3; continue
        sites.append(i)
        i += 2
        continue
    i += 1

print(f'Found {len(sites)} ?? sites in code')

# Step 3: for each site (right-to-left so byte offsets stay valid),
# extract LHS operand + RHS and rewrite.
#
# LHS extraction: walk backwards through valid access chars & balanced brackets,
# stopping at the leading $ that begins a variable expression.
#
# Valid access chars: \w (letters/digits/underscore), $, ->, ::
# Balanced containers: (), []
#
# RHS extraction: everything after ?? up to a boundary. Boundary is
# a top-level comma, semicolon, unmatched ), or unmatched ].
# "Top level" = not inside parens/brackets we've opened while walking RHS.

def find_lhs_start(src, mask, qq_pos):
    """Walk backward from qq_pos-1 to find the start of the LHS operand."""
    j = qq_pos - 1
    # Skip whitespace right before ??
    while j >= 0 and src[j] in ' \t':
        j -= 1
    end_of_lhs = j  # inclusive last char of LHS
    # Now walk backward through valid access syntax
    bracket_depth = 0  # []
    paren_depth = 0    # ()
    last_var_start = None
    while j >= 0:
        c = src[j]
        # Skip whitespace only when inside a bracket/paren
        if c in ' \t\n' and (bracket_depth > 0 or paren_depth > 0):
            j -= 1
            continue
        if c == ']':
            bracket_depth += 1
            j -= 1; continue
        if c == '[':
            bracket_depth -= 1
            if bracket_depth < 0:
                # left our expression
                j += 1; break
            j -= 1; continue
        if c == ')':
            paren_depth += 1
            j -= 1; continue
        if c == '(':
            paren_depth -= 1
            if paren_depth < 0:
                j += 1; break
            j -= 1; continue
        # Inside a bracketed/parenthesized region: consume anything
        if bracket_depth > 0 or paren_depth > 0:
            j -= 1; continue
        # Top level: only accept valid variable-access chars
        # Allowed: alphanumeric, _, $, ->, :: (via chars > : - )
        if c.isalnum() or c == '_':
            j -= 1; continue
        if c == '$':
            # start of a variable — LHS begins here
            last_var_start = j
            # keep walking to see if there's a preceding $obj-> (rare — nope, $ starts a var)
            # $ is always the start; stop
            j -= 1
            # peek back once for -> or :: chain: e.g. $this->partner_config['x']
            # Actually the -> chain is BEFORE $... wait no, $this->foo means '$' is at position of $this,
            # and ->foo comes AFTER. So $ IS the start. Stop.
            break
        if c == '>' and j > 0 and src[j-1] == '-':
            # -> operator; continue walking left (before the ->)
            j -= 2; continue
        if c == ':' and j > 0 and src[j-1] == ':':
            # :: operator; continue walking left
            j -= 2; continue
        # Any other char: end of expression
        j += 1
        break
    if last_var_start is not None:
        return last_var_start, end_of_lhs
    if j < 0:
        j = 0
    return j, end_of_lhs

def find_rhs_end(src, mask, qq_pos):
    """Find the end of RHS: first top-level , ; ) ] or } outside strings."""
    j = qq_pos + 2  # past ??
    # skip whitespace after ??
    while j < len(src) and src[j] in ' \t':
        j += 1
    rhs_start = j
    paren = 0
    bracket = 0
    brace = 0
    while j < len(src):
        # If we're in a string or comment, just advance
        if mask[j] != 0:
            j += 1; continue
        c = src[j]
        if c == '(':
            paren += 1
        elif c == ')':
            if paren == 0:
                # unmatched close paren — belongs to outer expression, stop here
                return rhs_start, j - 1
            paren -= 1
        elif c == '[':
            bracket += 1
        elif c == ']':
            if bracket == 0:
                return rhs_start, j - 1
            bracket -= 1
        elif c == '{':
            brace += 1
        elif c == '}':
            if brace == 0:
                return rhs_start, j - 1
            brace -= 1
        elif c == ',' and paren == 0 and bracket == 0 and brace == 0:
            return rhs_start, j - 1
        elif c == ';' and paren == 0 and bracket == 0 and brace == 0:
            return rhs_start, j - 1
        elif c == '\n' and paren == 0 and bracket == 0 and brace == 0:
            # Multi-line RHS is possible in nested arrays; only stop on \n if
            # we're at top level. But a single-line assignment ending without ;
            # is unusual. To be safe, keep going and let , ; or ) end it.
            # Actually, if line ends without stopping chars, that's the end.
            # Look ahead: if next non-space char is a "linebreak character" or
            # start of another statement, stop.
            # Simpler: consume trailing spaces, if next char is not part of an
            # expression continuation, stop.
            k = j + 1
            while k < len(src) and src[k] in ' \t':
                k += 1
            # If next line starts with a word char AND we're not in nested
            # brackets, treat as end.
            if k < len(src) and (src[k].isalnum() or src[k] in '_}'):
                return rhs_start, j - 1
        j += 1
    return rhs_start, len(src) - 1

# Process sites right-to-left so offsets stay valid
result = list(src)
for qq_pos in reversed(sites):
    lhs_start, lhs_end = find_lhs_start(src, mask, qq_pos)
    rhs_start, rhs_end = find_rhs_end(src, mask, qq_pos)

    lhs = src[lhs_start:lhs_end + 1].strip()
    rhs = src[rhs_start:rhs_end + 1].rstrip()

    if not lhs.startswith('$'):
        print(f'  skipping @{qq_pos}: LHS does not start with $ — got {lhs[:80]!r}')
        continue
    if not rhs:
        print(f'  skipping @{qq_pos}: empty RHS')
        continue

    replacement = f'(isset({lhs}) ? {lhs} : {rhs})'

    # Replace src[lhs_start .. rhs_end] with replacement
    # Use a marker approach: mutate result list
    result[lhs_start:rhs_end + 1] = list(replacement)

new_src = ''.join(result)
with open(sys.argv[2], 'w') as f:
    f.write(new_src)

# Confirm no ?? in code remains
mask2 = bytearray(len(new_src))
i = 0; n = len(new_src)
while i < n:
    c = new_src[i]
    if c == '/' and i+1 < n and new_src[i+1] == '/':
        while i < n and new_src[i] != '\n': mask2[i] = 2; i += 1
        continue
    if c == '/' and i+1 < n and new_src[i+1] == '*':
        mask2[i] = 2; mask2[i+1] = 2; i += 2
        while i < n-1 and not (new_src[i] == '*' and new_src[i+1] == '/'): mask2[i] = 2; i += 1
        if i < n: mask2[i] = 2
        if i+1 < n: mask2[i+1] = 2
        i += 2
        continue
    if c == '#':
        while i < n and new_src[i] != '\n': mask2[i] = 2; i += 1
        continue
    if c == "'":
        mask2[i] = 1; i += 1
        while i < n and new_src[i] != "'":
            if new_src[i] == '\\' and i+1 < n: mask2[i] = 1; mask2[i+1] = 1; i += 2; continue
            mask2[i] = 1; i += 1
        if i < n: mask2[i] = 1; i += 1
        continue
    if c == '"':
        mask2[i] = 1; i += 1
        while i < n and new_src[i] != '"':
            if new_src[i] == '\\' and i+1 < n: mask2[i] = 1; mask2[i+1] = 1; i += 2; continue
            mask2[i] = 1; i += 1
        if i < n: mask2[i] = 1; i += 1
        continue
    i += 1

remaining = 0
for i in range(n - 1):
    if new_src[i] == '?' and new_src[i+1] == '?' and mask2[i] == 0 and mask2[i+1] == 0:
        remaining += 1

print(f'Rewrote {len(sites)} sites; {remaining} ?? remain in code')
