# Create a GitHub issue

Create a new GitHub issue from a short description provided by the user.

## Arguments

- `$ARGUMENTS` — A brief description of the issue (required)

## Steps

1. Take the user's description from `$ARGUMENTS` and expand it into a well-structured GitHub issue:
   - **Title**: Short, imperative (under 70 characters)
   - **Body**: A `## Problem` section explaining the issue or need, and a `## Suggestion` section with a proposed approach (if applicable). Keep it concise.

2. Create the issue using `gh issue create` with the title and body.

3. Report the issue URL back to the user.
