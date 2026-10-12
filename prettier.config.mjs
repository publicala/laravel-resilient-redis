/** @type {import("prettier").Config} */
export default {
    proseWrap: "never",
    embeddedLanguageFormatting: "off",
    overrides: [
        {
            files: ["*.yml", "*.yaml"],
            options: {
                proseWrap: "preserve",
            },
        },
    ],
};
