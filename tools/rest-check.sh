#!/usr/bin/env bash
#
# The REST layer, exercised over real HTTP as a logged-out visitor AND as a
# logged-in customer.
#
# This exists because D-025 survived two phases of testing: the unit tests, the
# REST tests and an end-to-end curl run all passed while every logged-in
# generation 403'd, for the simple reason that none of them was logged in. A
# testbed whose only account is an administrator tests one audience twice.
#
# tests/run.php cannot cover this — it is pure PHP with no cookie jar and no
# HTTP. So this is a shell script rather than a new test framework.
#
# Usage:  bash tools/rest-check.sh [wizard_path]
#
# Requires the testbed to be up and the `testuser` customer account to exist:
#   wp user create testuser test@example.com --role=customer --user_pass=TestPass123

set -u

SITE="${AICAKE_TEST_SITE:-http://100.127.55.45:8080}"
API="$SITE/wp-json/aicake/v1"

# The page that carries the generator. Until D-047 this was a product page; the
# product-page generator is gone and the wizard is the only one there is, so the
# nonce-printing this script exists to check happens here or nowhere.
WIZARD="${1:-/ai-paveikslelis-vedlys/}"

# Which product the generate payload names. Independent of the page above: this
# script tests the REST layer, not the wizard, and 646 is a plain AI-enabled
# product with no wizard attached.
PRODUCT="${2:-646}"
USER_LOGIN="${AICAKE_TEST_USER:-testuser}"
USER_PASS="${AICAKE_TEST_PASS:-TestPass123}"

JAR="$(mktemp)"
ANON="$(mktemp)"
trap 'rm -f "$JAR" "$ANON"' EXIT

pass=0
fail=0

# ---------------------------------------------------------------- helpers

throttled=0

# check <label> <expected> <actual>
check() {
	if [ "$2" = "$3" ]; then
		printf '  ok    %-52s %s\n' "$1" "$3"
		pass=$(( pass + 1 ))
	else
		# A 429 is almost never a fault in the thing being asserted, and the
		# customer-facing message is *the same* for the session allowance and
		# the per-IP ceiling — so naming the code is the difference between a
		# red run that is understood and one that gets debugged for an hour.
		code=''

		if [ "$3" = '429' ] && [ -f "$JAR.out" ]; then
			code="$(json code < "$JAR.out")"
			throttled=1
		fi

		printf '  FAIL  %-52s expected %s, got %s%s\n' "$1" "$2" "$3" "${code:+ ($code)}"
		fail=$(( fail + 1 ))
	fi
}

json() {
	# json <field> — pulls a scalar out of the response on stdin. Good enough
	# for the flat shapes these endpoints return; do not grow it into a parser.
	grep -o "\"$1\":\(\"[^\"]*\"\|[a-z0-9]*\)" | head -1 | cut -d: -f2- | tr -d '"'
}

# generate <cookie-jar> <nonce> — prints the HTTP status.
# ASCII prompt on purpose: a non-ASCII one here fails as rest_invalid_json
# before the nonce is ever checked, which would silently pass this script.
generate() {
	printf '{"prompt":"meskiukas su spalvotais balionais","aspect":"1:1","product_id":%s,"variation_id":0}' "$PRODUCT" > "$JAR.body"
	curl -s -o "$JAR.out" -w '%{http_code}' \
		${1:+-b "$1"} \
		-H 'Content-Type: application/json' \
		${2:+-H "X-WP-Nonce: $2"} \
		--data-binary "@$JAR.body" \
		"$API/generate"
	rm -f "$JAR.body"
}

echo "REST check against $SITE — wizard $WIZARD, product $PRODUCT"

# ------------------------------------------------------------- anonymous

echo
echo "anonymous — the §7 path"

anon_session="$(curl -s -c "$ANON" "$API/session")"
anon_nonce="$(printf '%s' "$anon_session" | json nonce)"

check 'session reports logged_in false' 'false' "$(printf '%s' "$anon_session" | json logged_in)"
check 'generate with the session nonce' '202' "$(generate "$ANON" "$anon_nonce")"
check 'generate with no nonce is refused' '403' "$(generate "$ANON" '')"

page="$(curl -sL "$SITE$WIZARD")"
printed="$(printf '%s' "$page" | grep -o '"nonce":"[a-zA-Z0-9]*"' | head -1 | cut -d'"' -f4)"
check 'cacheable markup carries no nonce' 'empty' "${printed:-empty}"

# ------------------------------------------------------------- logged in

echo
echo "logged in — the D-025 path"

curl -s -o /dev/null -c "$JAR" \
	-d "log=$USER_LOGIN&pwd=$USER_PASS&rememberme=forever&wp-submit=Login" \
	"$SITE/wp-login.php"

if ! grep -q wordpress_logged_in "$JAR"; then
	echo "  FAIL  could not log in as $USER_LOGIN — is the account there?"
	exit 1
fi

page="$(curl -sL -b "$JAR" "$SITE$WIZARD")"
printed="$(printf '%s' "$page" | grep -o '"nonce":"[a-zA-Z0-9]*"' | head -1 | cut -d'"' -f4)"

check 'page prints a nonce for a logged-in user' 'yes' "$([ -n "$printed" ] && echo yes || echo no)"

# The endpoint called the way the pre-D-025 JS called it: no nonce, so core
# authenticates nobody and hands back a nonce belonging to user 0.
bare="$(curl -s -b "$JAR" "$API/session")"
check 'session without a nonce sees user 0' 'false' "$(printf '%s' "$bare" | json logged_in)"
check 'that nonce is rejected by generate' '403' "$(generate "$JAR" "$(printf '%s' "$bare" | json nonce)")"

# The same endpoint called the way it is called now.
authed="$(curl -s -b "$JAR" -H "X-WP-Nonce: $printed" "$API/session")"
anon_allowance="$(printf '%s' "$anon_session" | json allowance)"
user_allowance="$(printf '%s' "$authed" | json allowance)"

check 'session with the printed nonce authenticates' 'true' "$(printf '%s' "$authed" | json logged_in)"
# §11.3: an account exists to buy a larger allowance. If these are equal it is
# not buying one, which is how D-025 hid — the 403 was the loud half.
check 'logged-in allowance exceeds anonymous' 'yes' \
	"$([ "${user_allowance:-0}" -gt "${anon_allowance:-0}" ] && echo yes || echo no)"
check 'generate with the printed nonce' '202' "$(generate "$JAR" "$printed")"

job="$(cat "$JAR.out" 2>/dev/null | json job_id)"

# Ownership must answer 404 rather than 403, or the id space is enumerable.
stranger_nonce="$(curl -s "$API/session" | json nonce)"
check 'a stranger polling the job gets 404' '404' \
	"$(curl -s -o /dev/null -w '%{http_code}' -H "X-WP-Nonce: $stranger_nonce" "$API/job/$job")"
check 'the owner can poll it' '200' \
	"$(curl -s -o /dev/null -w '%{http_code}' -b "$JAR" -H "X-WP-Nonce: $printed" "$API/job/$job")"

echo
echo "$pass passed, $fail failed"

if [ "$throttled" -eq 1 ]; then
	cat <<'NOTE'

  ^ At least one failure was a 429, so this run says nothing about the REST
    layer — the request never reached it. Two different limits produce that,
    with the same customer message, and the code above says which:

      aicake_session_limit  free_per_user / free_per_session — a day of
                            browser testing as the same user or session.
      aicake_ip_limit       ip_daily_ceiling, default 30, counted per IP.

    Lift the relevant one, re-run, and put it back — `tools/wizard-check.php`
    does exactly that around its own request and is the pattern to copy:

      wp eval 'AiCake\Plugin::instance()->settings()->update(
        array( "free_per_user" => 1000, "ip_daily_ceiling" => 1000 ) );'
NOTE
fi

[ "$fail" -eq 0 ]
