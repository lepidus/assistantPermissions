English | [Português Brasileiro](docs/README-pt_BR.md)

# Assistant Permissions

Configures **Assistant** roles to read submissions and existing reviews, recommend decisions,
and restrict file operations and communication. Authors and reviewers remain identifiable.
The editor makes the final decision.

Requires **OMP 3.5.0.x and PHP 8.2**.

## Configuration

1. Under **Settings → Website → Plugins → Upload a new plugin**, install the `.tar.gz` package and enable **Assistant Permissions**.
2. Under **Settings → Users & Roles → Roles**, create or edit a role with the **Assistant** permission level. The role can have any name.
3. Select **Internal Review** and **External Review**, then enable the options you need:

| Option | Effect |
| --- | --- |
| **This role is only allowed to recommend a review decision…** | Preselects recommend-only for new assignments. The choice can be changed for each submission. |
| **Allow read-only access to files** | Keeps downloads and **More Information**. Blocks uploading, selecting, editing, replacing and deleting files, and attaching files to recommendations. |
| **Prevent communication with authors and reviewers (keep their identities visible)** | Blocks contact with authors and reviewers. Allows **Notify** for editorial staff assigned to the stage. |

4. Save the role and assign the user to it under **Users & Roles**.
5. Open the submission and add the user under **Participants**, using that role. Select **Recommend only** in the assignment options to allow recommendations instead of final decisions. You can change this option by editing the participant.

File and communication restrictions are independent role settings. Recommendation permission belongs
to each submission assignment. The role sets its initial default; editing the role preserves existing assignment choices.
With the role options disabled, native file and communication permissions apply.
The plugin does not create roles automatically.

## Credits and license

Developed by [Lepidus Tecnologia](https://lepidus.com.br/).
Licensed under the [GNU General Public License v3.0](LICENSE).

Copyright (c) 2026 Lepidus Tecnologia

Copyright (c) 2026 SciELO
