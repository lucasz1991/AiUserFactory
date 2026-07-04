import { mkdir, rename, rm } from "node:fs/promises";
import { dirname, resolve } from "node:path";

const source = resolve("public/build/.vite/manifest.json");
const destination = resolve("public/build/manifest.json");

await mkdir(dirname(destination), { recursive: true });
await rm(destination, { force: true });
await rename(source, destination);
await rm(resolve("public/build/.vite"), { recursive: true, force: true });
