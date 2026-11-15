window.NX_SITE = {
  pngName: "vox-seas-tier-list.png",
  shareTitle: "Vox Seas Tier List",
  defaults: function (uid) {
    return {
      title: "VOX SEAS\nTIER LIST",
      date: "",
      ad: { text: "", image: "", link: "" },
      donate: { da: "", hub: "", qr: "" },
      credits: [],
      footer: [
        { title: "ROBLOX", sub: "roblox.com/vox-seas", href: "https://www.roblox.com/games/104067066727140/Vox-Seas" },
      ],
      tiers: ["S", "A", "B", "C", "D"].map(function (label) {
        return { id: uid(), label: label, logo: "", items: [] };
      }),
    };
  },
};
