// Introspection JSON in, the SDL `tsquid-schema fetch` writes out: graphql-js printSchema over lexicographicSortSchema.
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';

const require = createRequire(process.env.GRAPHQL_FROM ?? import.meta.url);
const { buildClientSchema, lexicographicSortSchema, printSchema } = require('graphql');
const data = JSON.parse(readFileSync(process.argv[2], 'utf8'));

process.stdout.write(printSchema(lexicographicSortSchema(buildClientSchema(data))) + '\n');
