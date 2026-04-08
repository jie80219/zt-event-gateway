# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

This is a **Implementation of a Distributed Transaction API Gateway Based On SPIFFE/SPIRE Zero-Trust Architecture and Event-Driven Framework** - a collection of production-ready agents, skills, hooks, commands, rules, and MCP configurations. The project provides battle-tested workflows for software development using Claude Code.

## Stack
- **Language / Runtime**：PHP `^8.3`
- **HTTP Gateway**：OpenSwoole（非 Swow，見下方 SPIFFE 章節說明）
- **Messaging**：RabbitMQ via `php-amqplib/php-amqplib ^3.7.4`
- **Identity**：SPIRE Server + Agent，信任域 `zt.local`；本地 path package `packages/php-spiffe`（symlink）
- **Event store / Saga**：`prooph/event-store ^7.9`、`prooph/pdo-event-store ^1.15`
- **Supporting**：`ramsey/uuid`、`monolog/monolog`、`vlucas/phpdotenv`、`guzzlehttp/guzzle`、`sdpmlab/anser`、`sdpmlab/anser-action`
- **Tests**：`phpunit/phpunit ^10.5`

## Running Tests

```bash
# Run all tests
node tests/run-all.js

# Run individual test files
node tests/lib/utils.test.js
node tests/lib/package-manager.test.js
node tests/hooks/hooks.test.js
```

## Architecture

The project is organized into several core components:

- **agents/** - Specialized subagents for delegation (planner, code-reviewer, tdd-guide, etc.)
- **skills/** - Workflow definitions and domain knowledge (coding standards, patterns, testing)
- **commands/** - Slash commands invoked by users (/tdd, /plan, /e2e, etc.)
- **hooks/** - Trigger-based automations (session persistence, pre/post-tool hooks)
- **rules/** - Always-follow guidelines (security, coding style, testing requirements)
- **mcp-configs/** - MCP server configurations for external integrations
- **scripts/** - Cross-platform Node.js utilities for hooks and setup
- **tests/** - Test suite for scripts and utilities

## Key Commands

- `/tdd` - Test-driven development workflow
- `/plan` - Implementation planning
- `/e2e` - Generate and run E2E tests
- `/code-review` - Quality review
- `/build-fix` - Fix build errors
- `/learn` - Extract patterns from sessions
- `/skill-create` - Generate skills from git history

## Development Notes

- Package manager detection: npm, pnpm, yarn, bun (configurable via `CLAUDE_PACKAGE_MANAGER` env var or project config)
- Cross-platform: Windows, macOS, Linux support via Node.js scripts
- Agent format: Markdown with YAML frontmatter (name, description, tools, model)
- Skill format: Markdown with clear sections for when to use, how it works, examples
- Skill placement: Curated in skills/; generated/imported under ~/.claude/skills/. See docs/SKILL-PLACEMENT-POLICY.md
- Hook format: JSON with matcher conditions and command/notification hooks

## Contributing

Follow the formats in CONTRIBUTING.md:
- Agents: Markdown with frontmatter (name, description, tools, model)
- Skills: Clear sections (When to Use, How It Works, Examples)
- Commands: Markdown with description frontmatter
- Hooks: JSON with matcher and hooks array

File naming: lowercase with hyphens (e.g., `python-reviewer.md`, `tdd-workflow.md`)

## Skills

Use the following skills when working on related files:

| File(s) | Skill |
|---------|-------|
| `README.md` | `/readme` |
| `.github/workflows/*.yml` | `/ci-workflow` |

When spawning subagents, always pass conventions from the respective skill into the agent's prompt.